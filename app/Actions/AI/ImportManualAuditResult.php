<?php

namespace App\Actions\AI;

use App\Data\AI\AuditResult;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\AiManualPackage;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\AuditInputBuilder;
use App\Services\AI\AuditResultValidator;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;

final class ImportManualAuditResult
{
    public function __construct(
        private AuditResultValidator $validator,
        private AuditInputBuilder $inputBuilder,
    ) {}

    /** @param array<string,mixed> $payload */
    public function execute(
        AiExecution $execution,
        array $payload,
        ?string $provider = null,
        ?string $model = null,
        ?string $actualCost = null,
        ?string $costCurrency = null,
        ?User $actor = null,
    ): AuditResult {
        [$provider, $model, $actualCost, $costCurrency] = $this->normalizeMetadata($provider, $model, $actualCost, $costCurrency);
        $result = $this->validator->validate($payload);
        $normalizedPayload = $result->toArray();
        $payloadHash = CanonicalJson::hash($normalizedPayload);

        return DB::transaction(function () use (
            $execution,
            $result,
            $normalizedPayload,
            $payloadHash,
            $provider,
            $model,
            $actualCost,
            $costCurrency,
        ): AuditResult {
            /** @var AiExecution $locked */
            $locked = AiExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();

            if ($locked->stage !== AiExecutionStage::Audit || $locked->mode !== AiExecutionMode::Manual) {
                throw new AiPipelineException('AI_AUDIT_RESULT_EXECUTION_INVALID');
            }

            if ($locked->status === AiExecutionStatus::Succeeded) {
                if (! is_array($locked->audit_report)
                    || CanonicalJson::hash($locked->audit_report) !== $payloadHash) {
                    throw new AiPipelineException('AI_AUDIT_RESULT_IDEMPOTENCY_CONFLICT');
                }
                return $this->validator->validate($locked->audit_report);
            }

            if ($locked->status !== AiExecutionStatus::WaitingManual) {
                throw new AiPipelineException('AI_AUDIT_RESULT_NOT_WAITING_MANUAL');
            }

            /** @var AiManualPackage|null $manualPackage */
            $manualPackage = AiManualPackage::query()->where('ai_execution_id', $locked->id)->first();
            if (! $manualPackage) {
                throw new AiPipelineException('AI_AUDIT_MANUAL_PACKAGE_MISSING');
            }

            /** @var PlanningRequest|null $request */
            $request = PlanningRequest::query()->whereKey($locked->request_id)->lockForUpdate()->first();
            if (! $request
                || $request->status !== PlanningRequestStatus::AUDITORIA_IA
                || (int) $request->input_revision !== (int) $locked->input_revision) {
                throw new AiPipelineException('AI_AUDIT_RESULT_REQUEST_STALE');
            }

            // Reconstruye y valida nuevamente la versión fuente exacta antes de
            // cerrar la auditoría; evita aceptar reportes sobre una versión vieja.
            $this->inputBuilder->rebuildForExecution($locked->fresh(['request', 'promptVersion']));

            $finishedAt = now();
            $startedAt = $locked->started_at ?? $manualPackage->created_at ?? $locked->created_at ?? $finishedAt;
            $durationMs = max(0, (int) $startedAt->diffInMilliseconds($finishedAt));

            $locked->forceFill([
                'provider' => $provider,
                'model' => $model,
                'status' => AiExecutionStatus::Succeeded->value,
                'started_at' => $locked->started_at ?? $startedAt,
                'finished_at' => $finishedAt,
                'duration_ms' => $durationMs,
                'error_code' => null,
                'sanitized_error' => null,
                'actual_cost' => $actualCost,
                'cost_currency' => $costCurrency,
                'resulting_version_id' => null,
                'audit_report' => $normalizedPayload,
            ])->save();

            return $result;
        }, attempts: 3);
    }

    /** @return array{0:?string,1:?string,2:?string,3:?string} */
    private function normalizeMetadata(?string $provider, ?string $model, ?string $actualCost, ?string $costCurrency): array
    {
        $provider = $this->nullableText($provider, 128, 'AI_AUDIT_PROVIDER_INVALID');
        $model = $this->nullableText($model, 128, 'AI_AUDIT_MODEL_INVALID');
        if (($provider === null) !== ($model === null)) {
            throw new AiPipelineException('AI_AUDIT_PROVIDER_MODEL_PAIR_REQUIRED');
        }

        $actualCost = $actualCost === null ? null : trim($actualCost);
        $costCurrency = $costCurrency === null ? null : strtoupper(trim($costCurrency));
        $actualCost = $actualCost === '' ? null : $actualCost;
        $costCurrency = $costCurrency === '' ? null : $costCurrency;

        if ($actualCost === null) {
            if ($costCurrency !== null) {
                throw new AiPipelineException('AI_AUDIT_COST_CURRENCY_WITHOUT_COST');
            }
        } else {
            if (! is_numeric($actualCost) || (float) $actualCost < 0) {
                throw new AiPipelineException('AI_AUDIT_ACTUAL_COST_INVALID');
            }
            if ($costCurrency === null || preg_match('/^[A-Z]{3}$/', $costCurrency) !== 1) {
                throw new AiPipelineException('AI_AUDIT_COST_CURRENCY_INVALID');
            }
            if (preg_match('/^\d{1,10}(?:\.\d{1,8})?$/', $actualCost) !== 1) {
                throw new AiPipelineException('AI_AUDIT_ACTUAL_COST_PRECISION_INVALID');
            }
        }

        return [$provider, $model, $actualCost, $costCurrency];
    }

    private function nullableText(?string $value, int $max, string $errorCode): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new AiPipelineException($errorCode);
        }

        return $value;
    }
}
