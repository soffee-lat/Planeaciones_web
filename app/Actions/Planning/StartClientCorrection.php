<?php

namespace App\Actions\Planning;

use App\Actions\Commerce\ConsumePlanningReservation;
use App\Actions\Commerce\ReserveClientCorrection;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\CorrectionRequestStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Exceptions\ClientCorrectionException;
use App\Models\AiExecution;
use App\Models\CorrectionRequest;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\CorrectionPromptPolicy;
use App\Services\AI\CorrectionPromptSelector;
use App\Services\Planning\ClientCorrectionPolicy;
use App\Services\Planning\PlanningRequestStateMachine;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class StartClientCorrection
{
    public function __construct(
        private ClientCorrectionPolicy $clientPolicy,
        private CorrectionPromptSelector $promptSelector,
        private CorrectionPromptPolicy $promptPolicy,
        private ReserveClientCorrection $reserve,
        private ConsumePlanningReservation $consume,
        private PlanningRequestStateMachine $stateMachine,
    ) {}

    public function execute(CorrectionRequest|int $correction, User $actor, ?string $correlationId = null): PlanningRequest
    {
        $correctionId = $correction instanceof CorrectionRequest ? $correction->id : $correction;
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        return DB::transaction(function () use ($correctionId, $actor, $correlationId): PlanningRequest {
            $lockedCorrection = CorrectionRequest::query()->whereKey($correctionId)->lockForUpdate()->firstOrFail();
            $request = PlanningRequest::query()->whereKey($lockedCorrection->request_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('manageCorrection', $request);

            if ($lockedCorrection->status === CorrectionRequestStatus::Processing
                && $request->status === PlanningRequestStatus::CORRECCION_IA) {
                $existing = AiExecution::query()
                    ->where('request_id', $request->id)
                    ->where('stage', AiExecutionStage::Correction->value)
                    ->where('input_manifest->source_kind', 'client')
                    ->where('input_manifest->source_correction_request_id', $lockedCorrection->id)
                    ->first();
                if ($existing) {
                    return $request->fresh();
                }
                throw new ClientCorrectionException('CLIENT_CORRECTION_IDEMPOTENCY_CONFLICT');
            }

            if ($lockedCorrection->status !== CorrectionRequestStatus::Requested
                || $request->status !== PlanningRequestStatus::CORRECCION_SOLICITADA) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_START_STATE_INVALID');
            }
            $document = $request->document()->lockForUpdate()->first();
            if (! $document || (int) $document->current_version_id !== (int) $lockedCorrection->source_version_id) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_SOURCE_NOT_CURRENT');
            }

            $sourceVersion = $lockedCorrection->sourceVersion()->firstOrFail();
            if ($sourceVersion->content_hash === null) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_SOURCE_NOT_CURRENT');
            }
            $audit = AiExecution::query()
                ->where('request_id', $request->id)
                ->where('stage', AiExecutionStage::Audit->value)
                ->where('status', AiExecutionStatus::Succeeded->value)
                ->where('input_revision', $request->input_revision)
                ->where('input_manifest->source_version_id', $sourceVersion->id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $audit || ! is_array($audit->audit_report) || ($audit->audit_report['passed'] ?? null) !== true) {
                throw new AiPipelineException('AI_CLIENT_CORRECTION_PASSED_AUDIT_REQUIRED');
            }

            $prompt = $this->promptSelector->activeForUpdate();
            $this->promptPolicy->assertReady($prompt);

            // La reserva se ata al periodo histórico de la solicitud y NO exige
            // que la suscripción siga activa: una corrección solicitada a tiempo
            // conserva los derechos congelados del trabajo original.
            $reservation = $this->reserve->execute($request, $lockedCorrection);
            $round = AiExecution::query()
                ->where('request_id', $request->id)
                ->where('input_revision', $request->input_revision)
                ->where('stage', AiExecutionStage::Correction->value)
                ->count() + 1;

            $lockedCorrection->forceFill([
                'status' => CorrectionRequestStatus::Accepted->value,
                'assigned_to' => $actor->id,
            ])->save();

            $auditHash = CanonicalJson::hash($audit->audit_report);
            $correctionPayloadHash = $this->clientPolicy->payloadHash($lockedCorrection->fresh());
            $operationKey = sprintf(
                'planning-request:%d:client-correction:%d:source:%d:prompt:%d',
                $request->id,
                $lockedCorrection->id,
                $sourceVersion->id,
                $prompt->id,
            );

            $execution = AiExecution::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
            if (! $execution) {
                $execution = AiExecution::query()->create([
                    'request_id' => $request->id,
                    'format_version_id' => null,
                    'stage' => AiExecutionStage::Correction->value,
                    'mode' => AiExecutionMode::Manual->value,
                    'provider' => null,
                    'model' => null,
                    'prompt_version_id' => $prompt->id,
                    'input_revision' => (int) $request->input_revision,
                    'input_manifest' => [
                        'schema_version' => 1,
                        'request_id' => (int) $request->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_kind' => 'client',
                        'source_version_id' => (int) $sourceVersion->id,
                        'source_content_hash' => $sourceVersion->content_hash,
                        'source_audit_execution_id' => (int) $audit->id,
                        'source_audit_report_hash' => $auditHash,
                        'source_correction_request_id' => (int) $lockedCorrection->id,
                        'source_correction_payload_hash' => $correctionPayloadHash,
                        'section_keys' => array_values($lockedCorrection->section_keys),
                        'correction_round' => $round,
                        'correlation_id' => $correlationId,
                    ],
                    'rendered_prompt_hash' => null,
                    'private_payload_file_id' => null,
                    'operation_key' => $operationKey,
                    'status' => AiExecutionStatus::Pending->value,
                    'started_at' => null,
                    'finished_at' => null,
                    'duration_ms' => null,
                    'error_code' => null,
                    'sanitized_error' => null,
                    'estimated_cost' => null,
                    'actual_cost' => null,
                    'cost_currency' => null,
                    'resulting_version_id' => null,
                    'audit_report' => null,
                ]);
            }

            $eventKey = 'ai-execution:' . $execution->id . ':correction-dispatch';
            $event = OutboxEvent::query()->where('event_key', $eventKey)->lockForUpdate()->first();
            if (! $event) {
                OutboxEvent::query()->create([
                    'event_key' => $eventKey,
                    'type' => OutboxEventType::PlanningCorrectionRequested->value,
                    'aggregate_id' => $request->id,
                    'payload' => [
                        'request_id' => (int) $request->id,
                        'ai_execution_id' => (int) $execution->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_version_id' => (int) $sourceVersion->id,
                        'correction_request_id' => (int) $lockedCorrection->id,
                        'correction_round' => $round,
                        'correlation_id' => $correlationId,
                    ],
                    'published_at' => null,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }

            ($this->consume)($reservation);
            $lockedCorrection->forceFill(['status' => CorrectionRequestStatus::Processing->value])->save();

            $this->stateMachine->assertCanTransition($request->status, PlanningRequestStatus::CORRECCION_IA);
            $request->forceFill([
                'status' => PlanningRequestStatus::CORRECCION_IA->value,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();
            $request->stateEvents()->create([
                'from_status' => PlanningRequestStatus::CORRECCION_SOLICITADA->value,
                'to_status' => PlanningRequestStatus::CORRECCION_IA->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'client_correction_processing',
                'correlation_id' => $correlationId,
            ]);

            return $request->fresh();
        }, attempts: 3);
    }
}
