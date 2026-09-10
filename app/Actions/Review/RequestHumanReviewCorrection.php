<?php

namespace App\Actions\Review;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\HumanReviewStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Exceptions\HumanReviewException;
use App\Models\AiExecution;
use App\Models\HumanReview;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\CorrectionPromptPolicy;
use App\Services\AI\CorrectionPromptSelector;
use App\Services\Planning\PlanningRequestStateMachine;
use App\Services\Review\HumanReviewCorrectionPolicy;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class RequestHumanReviewCorrection
{
    public function __construct(
        private HumanReviewCorrectionPolicy $correctionPolicy,
        private CorrectionPromptSelector $promptSelector,
        private CorrectionPromptPolicy $promptPolicy,
        private PlanningRequestStateMachine $stateMachine,
    ) {}

    public function execute(HumanReview $review, User $actor, ?string $correlationId = null): PlanningRequest
    {
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        return DB::transaction(function () use ($review, $actor, $correlationId): PlanningRequest {
            $locked = HumanReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $assignment = $locked->assignment()->lockForUpdate()->firstOrFail();
            $request = $locked->request()->lockForUpdate()->firstOrFail();
            $this->assertReviewer($actor, $locked, $assignment);

            if ($locked->status === HumanReviewStatus::ChangesRequested) {
                $existing = AiExecution::query()
                    ->where('request_id', $request->id)
                    ->where('stage', AiExecutionStage::Correction->value)
                    ->where('input_manifest->source_kind', 'human_review')
                    ->where('input_manifest->source_review_id', $locked->id)
                    ->first();
                if ($existing && $request->status === PlanningRequestStatus::CORRECCION_IA) {
                    return $request->fresh();
                }
                throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_IDEMPOTENCY_CONFLICT');
            }

            if ($locked->status !== HumanReviewStatus::InProgress
                || $assignment->status !== ReviewAssignmentStatus::InProgress
                || $request->status !== PlanningRequestStatus::REVISION_HUMANA) {
                throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_STATE_INVALID');
            }

            $document = $request->document()->lockForUpdate()->first();
            if (! $document || (int) $document->current_version_id !== (int) $locked->version_id) {
                throw new HumanReviewException('HUMAN_REVIEW_VERSION_STALE');
            }

            $context = $this->correctionPolicy->build($locked);
            $prompt = $this->promptSelector->activeForUpdate();
            $this->promptPolicy->assertReady($prompt);

            $audit = AiExecution::query()
                ->where('request_id', $request->id)
                ->where('stage', AiExecutionStage::Audit->value)
                ->where('status', AiExecutionStatus::Succeeded->value)
                ->where('input_revision', $request->input_revision)
                ->where('input_manifest->source_version_id', $locked->version_id)
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $audit || ! is_array($audit->audit_report) || ($audit->audit_report['passed'] ?? null) !== true) {
                throw new HumanReviewException('HUMAN_REVIEW_CORRECTION_PASSED_AUDIT_REQUIRED');
            }

            $sourceVersion = $locked->version()->firstOrFail();
            $auditHash = CanonicalJson::hash($audit->audit_report);
            $operationKey = sprintf(
                'planning-request:%d:human-review-correction:review:%d:source:%d:prompt:%d',
                $request->id,
                $locked->id,
                $locked->version_id,
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
                        'source_kind' => 'human_review',
                        'source_version_id' => (int) $locked->version_id,
                        'source_content_hash' => $sourceVersion->content_hash,
                        'source_audit_execution_id' => (int) $audit->id,
                        'source_audit_report_hash' => $auditHash,
                        'source_review_id' => (int) $locked->id,
                        'source_review_payload_hash' => $context['payloadHash'],
                        'section_keys' => $context['sectionKeys'],
                        'correction_round' => $context['correctionRound'],
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
                OutboxEvent::query()->create([
                    'event_key' => $eventKey,
                    'type' => OutboxEventType::PlanningCorrectionRequested->value,
                    'aggregate_id' => $request->id,
                    'payload' => [
                        'request_id' => (int) $request->id,
                        'ai_execution_id' => (int) $correction->id,
                        'input_revision' => (int) $request->input_revision,
                        'source_version_id' => (int) $locked->version_id,
                        'source_review_id' => (int) $locked->id,
                        'correction_round' => $context['correctionRound'],
                        'correlation_id' => $correlationId,
                    ],
                    'published_at' => null,
                    'attempts' => 0,
                    'available_at' => now(),
                ]);
            }

            $now = now();
            $locked->forceFill([
                'status' => HumanReviewStatus::ChangesRequested->value,
                'decided_at' => $now,
            ])->save();
            $assignment->forceFill([
                'status' => ReviewAssignmentStatus::Completed->value,
                'ended_at' => $now,
                'ended_by' => $actor->id,
                'ended_reason' => 'human_review_changes_requested',
            ])->save();

            $this->stateMachine->assertCanTransition($request->status, PlanningRequestStatus::CORRECCION_IA);
            $request->forceFill([
                'status' => PlanningRequestStatus::CORRECCION_IA->value,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();
            $request->stateEvents()->create([
                'from_status' => PlanningRequestStatus::REVISION_HUMANA->value,
                'to_status' => PlanningRequestStatus::CORRECCION_IA->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'human_review_changes_requested',
                'correlation_id' => $correlationId,
            ]);

            return $request->fresh();
        }, attempts: 3);
    }

    private function assertReviewer(User $actor, HumanReview $review, $assignment): void
    {
        if ($actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasRole(RoleCode::Reviewer)
            || (int) $review->reviewer_id !== (int) $actor->id
            || (int) $assignment->reviewer_id !== (int) $actor->id) {
            throw new HumanReviewException('HUMAN_REVIEW_REVIEWER_FORBIDDEN');
        }
    }
}
