<?php

namespace App\Actions\Review;

use App\Enums\ApprovalKind;
use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Exceptions\HumanReviewException;
use App\Models\Approval;
use App\Models\HumanReview;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ApproveHumanReview
{
    public function __construct(private PlanningRequestStateMachine $stateMachine) {}

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

            $existing = Approval::query()
                ->where('version_id', $locked->version_id)
                ->where('kind', ApprovalKind::Human->value)
                ->lockForUpdate()
                ->first();
            if ($locked->status === HumanReviewStatus::Approved
                && $assignment->status === ReviewAssignmentStatus::Completed
                && $request->status === PlanningRequestStatus::APROBADA
                && $existing
                && (int) $existing->review_id === (int) $locked->id) {
                return $request->fresh();
            }

            if ($locked->status !== HumanReviewStatus::InProgress
                || $assignment->status !== ReviewAssignmentStatus::InProgress
                || $request->status !== PlanningRequestStatus::REVISION_HUMANA) {
                throw new HumanReviewException('HUMAN_REVIEW_APPROVAL_STATE_INVALID');
            }

            $document = $request->document()->lockForUpdate()->first();
            if (! $document || (int) $document->current_version_id !== (int) $locked->version_id) {
                throw new HumanReviewException('HUMAN_REVIEW_VERSION_STALE');
            }

            $requiredIds = $locked->checklistVersion->items()->where('required', true)->pluck('id');
            if ($requiredIds->isEmpty()) {
                throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_REQUIRED');
            }
            $passedRequired = $locked->responses()
                ->whereIn('checklist_item_id', $requiredIds)
                ->where('passed', true)
                ->distinct('checklist_item_id')
                ->count('checklist_item_id');
            if ($passedRequired !== $requiredIds->count()) {
                throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_INCOMPLETE');
            }

            $now = now();
            $locked->forceFill([
                'status' => HumanReviewStatus::Approved->value,
                'decided_at' => $now,
            ])->save();
            $assignment->forceFill([
                'status' => ReviewAssignmentStatus::Completed->value,
                'ended_at' => $now,
                'ended_by' => $actor->id,
                'ended_reason' => 'human_review_approved',
            ])->save();

            if ($existing && (int) $existing->review_id !== (int) $locked->id) {
                throw new HumanReviewException('HUMAN_REVIEW_APPROVAL_IDEMPOTENCY_CONFLICT');
            }
            if (! $existing) {
                Approval::query()->create([
                    'request_id' => $request->id,
                    'version_id' => $locked->version_id,
                    'kind' => ApprovalKind::Human->value,
                    'ai_execution_id' => null,
                    'review_id' => $locked->id,
                    'actor_id' => $actor->id,
                    'approved_at' => $now,
                ]);
            }

            $this->stateMachine->assertCanTransition($request->status, PlanningRequestStatus::APROBADA);
            $request->forceFill([
                'status' => PlanningRequestStatus::APROBADA->value,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();
            $request->stateEvents()->create([
                'from_status' => PlanningRequestStatus::REVISION_HUMANA->value,
                'to_status' => PlanningRequestStatus::APROBADA->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'human_review_approved',
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
