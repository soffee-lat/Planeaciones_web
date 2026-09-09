<?php

namespace App\Actions\AI;

use App\Actions\Review\AssignReviewer;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\ApprovalKind;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\Approval;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\AuditResultValidator;
use App\Services\AI\CorrectionPromptPolicy;
use App\Services\AI\CorrectionPromptSelector;
use App\Services\AI\InternalCorrectionPolicy;
use App\Services\AI\RequestBlockManager;
use App\Services\Planning\PlanningRequestStateMachine;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RouteAuditResult
{
    public function __construct(
        private AuditResultValidator $auditValidator,
        private InternalCorrectionPolicy $correctionPolicy,
        private CorrectionPromptSelector $correctionPromptSelector,
        private CorrectionPromptPolicy $correctionPromptPolicy,
        private PlanningRequestStateMachine $stateMachine,
        private RequestBlockManager $blocks,
        private AssignReviewer $assignReviewer,
    ) {}

    public function execute(AiExecution $auditExecution, ?User $actor = null, ?string $correlationId = null): PlanningRequest
    {
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new AiPipelineException('AI_AUDIT_ROUTING_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        return DB::transaction(function () use ($auditExecution, $actor, $correlationId): PlanningRequest {
            $audit = AiExecution::query()->whereKey($auditExecution->id)->lockForUpdate()->firstOrFail();
            if ($audit->stage !== AiExecutionStage::Audit
                || $audit->mode !== AiExecutionMode::Manual
                || $audit->status !== AiExecutionStatus::Succeeded
                || ! is_array($audit->audit_report)) {
                throw new AiPipelineException('AI_AUDIT_ROUTING_EXECUTION_NOT_READY');
            }

            $request = PlanningRequest::query()->whereKey($audit->request_id)->lockForUpdate()->firstOrFail();
            $sourceVersionId = (int) ($audit->input_manifest['source_version_id'] ?? 0);
            $sourceContentHash = (string) ($audit->input_manifest['source_content_hash'] ?? '');
            $version = DocumentVersion::query()->with('document')->whereKey($sourceVersionId)->first();
            if (! $version || ! $version->document
                || (int) $version->document->request_id !== (int) $request->id
                || (int) $version->document->current_version_id !== $sourceVersionId
                || $version->content_hash !== $sourceContentHash
                || (int) $audit->input_revision !== (int) $request->input_revision) {
                throw new AiPipelineException('AI_AUDIT_ROUTING_SOURCE_STALE');
            }

            $result = $this->auditValidator->validate($audit->audit_report);

            if ($request->status !== PlanningRequestStatus::AUDITORIA_IA) {
                if ($result->passed && in_array($request->status, [PlanningRequestStatus::APROBADA, PlanningRequestStatus::REVISION_HUMANA], true)) {
                    return $request->fresh();
                }
                if (! $result->passed && $request->status === PlanningRequestStatus::CORRECCION_IA) {
                    return $request->fresh();
                }
                throw new AiPipelineException('AI_AUDIT_ROUTING_REQUEST_STATE_INVALID');
            }

            if ($result->passed) {
                $this->blocks->resolve($request, 'ai_quality_attention', AiExecutionStage::Audit->value, $actor);
                $approval = Approval::query()
                    ->where('version_id', $version->id)
                    ->where('kind', ApprovalKind::Ai->value)
                    ->lockForUpdate()
                    ->first();
                if (! $approval) {
                    Approval::query()->create([
                        'request_id' => $request->id,
                        'version_id' => $version->id,
                        'kind' => ApprovalKind::Ai->value,
                        'ai_execution_id' => $audit->id,
                        'review_id' => null,
                        'actor_id' => $actor?->id,
                        'approved_at' => now(),
                    ]);
                } elseif ((int) $approval->request_id !== (int) $request->id || (int) $approval->ai_execution_id !== (int) $audit->id) {
                    throw new AiPipelineException('AI_APPROVAL_IDEMPOTENCY_CONFLICT');
                }

                $to = $request->human_review_required_snapshot
                    ? PlanningRequestStatus::REVISION_HUMANA
                    : PlanningRequestStatus::APROBADA;
                $this->transition(
                    $request,
                    $to,
                    $actor,
                    $correlationId,
                    $to === PlanningRequestStatus::REVISION_HUMANA
                        ? 'audit_passed_human_review_required'
                        : 'audit_passed_ai_approved',
                );

                if ($to === PlanningRequestStatus::REVISION_HUMANA) {
                    $this->assignReviewer->execute($request->fresh(), $correlationId);
                }

                return $request->fresh();
            }

            try {
                $sectionKeys = $this->correctionPolicy->sectionKeys($result);
                $round = $this->correctionPolicy->assertRoundAvailable($request);
            } catch (AiPipelineException $e) {
                if (! in_array($e->errorCode, [
                    'AI_CORRECTION_SCOPE_UNSAFE',
                    'AI_INTERNAL_CORRECTION_ROUND_LIMIT_REACHED',
                    'AI_INTERNAL_CORRECTION_COST_LIMIT_REACHED',
                    'AI_INTERNAL_CORRECTION_COST_CURRENCY_MISMATCH',
                ], true)) {
                    throw $e;
                }

                $this->blocks->open(
                    $request,
                    'ai_quality_attention',
                    AiExecutionStage::Audit->value,
                    [
                        'reason' => $e->errorCode,
                        'source_version_id' => $sourceVersionId,
                        'audit_execution_id' => (int) $audit->id,
                    ],
                    $correlationId,
                );

                return $request->fresh();
            }

            $prompt = $this->correctionPromptSelector->activeForUpdate();
            $this->correctionPromptPolicy->assertReady($prompt);
            $this->blocks->resolve($request, 'ai_quality_attention', AiExecutionStage::Audit->value, $actor);

            $auditHash = CanonicalJson::hash($audit->audit_report);
            $operationKey = sprintf(
                'planning-request:%d:correction:round:%d:source:%d:audit:%d:prompt:%d',
                $request->id,
                $round,
                $sourceVersionId,
                $audit->id,
                $prompt->id,
            );

            $correction = AiExecution::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
            if (! $correction) {
                $correction = AiExecution::query()->create([
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
                        'source_version_id' => $sourceVersionId,
                        'source_content_hash' => $sourceContentHash,
                        'source_audit_execution_id' => (int) $audit->id,
                        'source_audit_report_hash' => $auditHash,
                        'section_keys' => $sectionKeys,
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

            $eventKey = 'ai-execution:' . $correction->id . ':correction-dispatch';
            $event = OutboxEvent::query()->where('event_key', $eventKey)->lockForUpdate()->first();
            if (! $event) {
                $event = OutboxEvent::query()->create([
                    'event_key' => $eventKey,
                    'type' => OutboxEventType::PlanningCorrectionRequested->value,
                    'aggregate_id' => $request->id,
                    'payload' => [
                        'request_id' => (int) $request->id,
                        'ai_execution_id' => (int) $correction->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_version_id' => $sourceVersionId,
                        'source_audit_execution_id' => (int) $audit->id,
                        'correction_round' => $round,
                        'correlation_id' => $correlationId,
                    ],
                    'published_at' => null,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }

            if ($event->type !== OutboxEventType::PlanningCorrectionRequested
                || (int) $event->aggregate_id !== (int) $request->id
                || (int) ($event->payload['ai_execution_id'] ?? 0) !== (int) $correction->id
                || (int) ($event->payload['source_version_id'] ?? 0) !== $sourceVersionId) {
                throw new AiPipelineException('AI_CORRECTION_OUTBOX_IDEMPOTENCY_CONFLICT');
            }

            $this->transition($request, PlanningRequestStatus::CORRECCION_IA, $actor, $correlationId, 'audit_failed_correction_dispatched');

            return $request->fresh();
        }, attempts: 3);
    }

    private function transition(
        PlanningRequest $request,
        PlanningRequestStatus $to,
        ?User $actor,
        string $correlationId,
        string $reason,
    ): void {
        $from = $request->status;
        $this->stateMachine->assertCanTransition($from, $to);
        $request->forceFill([
            'status' => $to->value,
            'lock_version' => (int) $request->lock_version + 1,
        ])->save();
        $request->stateEvents()->create([
            'from_status' => $from->value,
            'to_status' => $to->value,
            'actor_id' => $actor?->id,
            'actor_type' => $actor ? 'user' : 'system',
            'reason' => $reason,
            'correlation_id' => $correlationId,
        ]);
    }
}
