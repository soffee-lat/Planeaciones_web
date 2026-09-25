<?php

namespace App\Http\Controllers\Admin;

use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Enums\RoleCode;
use App\Exceptions\AiContractException;
use App\Exceptions\AiPipelineException;
use App\Http\Controllers\Controller;
use App\Models\AiExecution;
use App\Models\CorrectionRequest;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

final class ManualAiOperationsController extends Controller
{
    public function summary(Request $request): JsonResponse
    {
        $this->administrator($request);

        $executions = $this->waitingManualQuery()
            ->orderBy('created_at')
            ->get(['id', 'request_id', 'stage', 'created_at']);

        $byStage = [
            AiExecutionStage::Generation->value => 0,
            AiExecutionStage::Audit->value => 0,
            AiExecutionStage::Correction->value => 0,
        ];

        foreach ($executions as $execution) {
            $byStage[$execution->stage->value] = ($byStage[$execution->stage->value] ?? 0) + 1;
        }

        $aiSignature = hash('sha256', $executions
            ->map(fn (AiExecution $execution): string => $execution->id . ':' . $execution->stage->value)
            ->implode('|'));

        $clientReviews = CorrectionRequest::query()
            ->where('type', CorrectionRequestType::Client->value)
            ->where('status', CorrectionRequestStatus::Requested->value)
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get(['id', 'request_id', 'requested_at']);

        $reviewSignature = hash('sha256', $clientReviews
            ->map(fn (CorrectionRequest $correction): string => $correction->id . ':' . $correction->request_id)
            ->implode('|'));

        return response()->json([
            'count' => $executions->count(),
            'signature' => hash('sha256', 'ai:' . $aiSignature . '|reviews:' . $reviewSignature),
            'by_stage' => $byStage,
            'oldest_at' => $executions->first()?->created_at?->toIso8601String(),
            'client_reviews' => [
                'count' => $clientReviews->count(),
                'signature' => $reviewSignature,
                'oldest_at' => $clientReviews->first()?->requested_at?->toIso8601String(),
            ],
        ]);
    }

    public function download(Request $request, AiExecution $execution): StreamedResponse
    {
        $this->administrator($request);
        $this->assertWaitingManual($execution);

        $package = $execution->manualPackage()->first();
        abort_unless($package, 404, 'Paquete manual no encontrado.');

        $disk = Storage::disk($package->disk);
        abort_unless($disk->exists($package->path), 404, 'El archivo del paquete ya no existe.');

        $bytes = $disk->get($package->path);
        abort_unless(hash('sha256', $bytes) === $package->checksum, 409, 'El checksum del paquete no coincide.');

        $filename = sprintf(
            'soffee-%s-execution-%d.json',
            $execution->stage->value,
            $execution->id,
        );

        return response()->streamDownload(
            static function () use ($bytes): void {
                echo $bytes;
            },
            $filename,
            ['Content-Type' => 'application/json; charset=UTF-8'],
        );
    }

    public function storeResult(
        Request $request,
        AiExecution $execution,
        ImportManualGenerationResult $importGeneration,
        ImportManualAuditResult $importAudit,
        ImportManualCorrectionResult $importCorrection,
        RouteAuditResult $routeAudit,
        ProcessOutboxEvent $processOutbox,
    ): RedirectResponse {
        $actor = $this->administrator($request);
        $this->assertWaitingManual($execution);

        $validated = $request->validate([
            'result_file' => ['required', 'file', 'max:10240'],
            'provider' => ['nullable', 'string', 'max:128', 'required_with:model'],
            'model' => ['nullable', 'string', 'max:128', 'required_with:provider'],
        ]);

        $provider = $this->nullableText($validated['provider'] ?? null);
        $model = $this->nullableText($validated['model'] ?? null);

        try {
            $contents = file_get_contents($validated['result_file']->getRealPath());
            if ($contents === false) {
                throw new AiPipelineException('AI_MANUAL_RESULT_FILE_UNREADABLE');
            }

            $payload = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($payload) || array_is_list($payload)) {
                throw new AiPipelineException('AI_MANUAL_RESULT_JSON_OBJECT_REQUIRED');
            }

            $message = match ($execution->stage) {
                AiExecutionStage::Generation => $this->importGeneration(
                    $execution,
                    $payload,
                    $actor,
                    $provider,
                    $model,
                    $importGeneration,
                ),
                AiExecutionStage::Audit => $this->importAudit(
                    $execution,
                    $payload,
                    $actor,
                    $provider,
                    $model,
                    $importAudit,
                    $routeAudit,
                ),
                AiExecutionStage::Correction => $this->importCorrection(
                    $execution,
                    $payload,
                    $actor,
                    $provider,
                    $model,
                    $importCorrection,
                ),
                default => throw new AiPipelineException('AI_MANUAL_WEB_STAGE_UNSUPPORTED'),
            };

            $prepared = $this->preparePendingForRequest(
                (int) $execution->request_id,
                $processOutbox,
            );

            $next = $this->waitingManualQuery()
                ->where('request_id', $execution->request_id)
                ->orderBy('id')
                ->first();

            return redirect('/admin/ai-operations')
                ->with('success', $message)
                ->with('info', $next
                    ? $this->nextStepMessage($next)
                    : ($prepared > 0
                        ? "Se prepararon {$prepared} paquete(s) para la siguiente etapa."
                        : 'No hay otro paquete manual pendiente para esta solicitud. Revisa el estado de la planeación.'));

        } catch (JsonException) {
            return back()->with('error', 'El archivo no contiene JSON válido.');
        } catch (AiContractException $e) {
            return back()->with('error', $e->getMessage());
        } catch (AiPipelineException $e) {
            return back()->with('error', $e->errorCode . ($e->detail ? ': ' . $e->detail : ''));
        } catch (Throwable $e) {
            report($e);

            return back()->with('error', 'No fue posible importar el resultado. Revisa el archivo y vuelve a intentarlo.');
        }
    }

    public function prepare(Request $request, ProcessOutboxEvent $processor): RedirectResponse
    {
        $this->administrator($request);

        $events = OutboxEvent::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(100)
            ->get();

        $processed = 0;
        $failed = 0;

        foreach ($events as $event) {
            try {
                if ($processor->execute($event)) {
                    $processed++;
                }
            } catch (Throwable $e) {
                report($e);
                $failed++;
            }
        }

        $message = "Paquetes preparados: {$processed}.";
        if ($failed > 0) {
            $message .= " Fallidos/reintentables: {$failed}.";
        }

        return redirect('/admin/ai-operations')->with(
            $failed > 0 ? 'warning' : 'success',
            $message,
        );
    }

    private function importGeneration(
        AiExecution $execution,
        array $payload,
        User $actor,
        ?string $provider,
        ?string $model,
        ImportManualGenerationResult $action,
    ): string {
        $version = $action->execute(
            $execution,
            $payload,
            $provider,
            $model,
            null,
            null,
            $actor,
        );

        return sprintf(
            'Generación importada correctamente. Se creó la versión %d.',
            $version->id,
        );
    }

    private function importAudit(
        AiExecution $execution,
        array $payload,
        User $actor,
        ?string $provider,
        ?string $model,
        ImportManualAuditResult $import,
        RouteAuditResult $route,
    ): string {
        $result = $import->execute(
            $execution,
            $payload,
            $provider,
            $model,
            null,
            null,
            $actor,
        );

        $correlationId = (string) ($execution->input_manifest['correlation_id'] ?? '');
        $route->execute(
            $execution->fresh(),
            $actor,
            Str::isUuid($correlationId) ? strtolower($correlationId) : null,
        );

        return $result->passed
            ? 'Auditoría importada y encaminada: aprobada.'
            : sprintf(
                'Auditoría importada y encaminada: %d hallazgo(s).',
                count($result->findings),
            );
    }

    private function importCorrection(
        AiExecution $execution,
        array $payload,
        User $actor,
        ?string $provider,
        ?string $model,
        ImportManualCorrectionResult $action,
    ): string {
        $version = $action->execute(
            $execution,
            $payload,
            $provider,
            $model,
            null,
            null,
            $actor,
        );

        return sprintf(
            'Corrección importada correctamente. Se creó la versión %d y se preparará una nueva auditoría.',
            $version->id,
        );
    }

    private function preparePendingForRequest(int $requestId, ProcessOutboxEvent $processor): int
    {
        $events = OutboxEvent::query()
            ->where('aggregate_id', $requestId)
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit(10)
            ->get();

        $processed = 0;

        foreach ($events as $event) {
            try {
                if ($processor->execute($event)) {
                    $processed++;
                }
            } catch (Throwable $e) {
                // La importación ya quedó protegida por idempotencia. El outbox
                // conserva el fallo y podrá reintentarse desde scheduler o UI.
                report($e);
            }
        }

        return $processed;
    }

    private function waitingManualQuery()
    {
        return AiExecution::query()
            ->where('mode', AiExecutionMode::Manual->value)
            ->where('status', AiExecutionStatus::WaitingManual->value)
            ->whereIn('stage', [
                AiExecutionStage::Generation->value,
                AiExecutionStage::Audit->value,
                AiExecutionStage::Correction->value,
            ])
            ->whereHas('manualPackage');
    }

    private function assertWaitingManual(AiExecution $execution): void
    {
        abort_unless(
            $execution->mode === AiExecutionMode::Manual
                && $execution->status === AiExecutionStatus::WaitingManual
                && in_array($execution->stage, [
                    AiExecutionStage::Generation,
                    AiExecutionStage::Audit,
                    AiExecutionStage::Correction,
                ], true),
            409,
            'La ejecución no está esperando procesamiento manual.',
        );
    }

    private function administrator(Request $request): User
    {
        $user = $request->user();

        abort_unless(
            $user instanceof User
                && $user->status === 'active'
                && $user->hasRole(RoleCode::Administrator),
            403,
        );

        return $user;
    }

    private function nextStepMessage(AiExecution $execution): string
    {
        if ($execution->stage === AiExecutionStage::Generation) {
            return 'Siguiente paso listo: Generación. Descarga el paquete de generación y vuelve con el JSON resultante.';
        }

        if ($execution->stage === AiExecutionStage::Correction) {
            return 'Siguiente paso listo: Corrección. Descarga el paquete de corrección, procesa únicamente los hallazgos autorizados y sube el resultado.';
        }

        $hasPriorCorrection = AiExecution::query()
            ->where('request_id', $execution->request_id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->where('id', '<', $execution->id)
            ->exists();

        return $hasPriorCorrection
            ? 'Siguiente paso listo: Reauditoría. Descarga el nuevo paquete y comprueba la versión corregida.'
            : 'Siguiente paso listo: Auditoría. Descarga el paquete de auditoría y vuelve con el JSON del auditor.';
    }

    private function nullableText(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
