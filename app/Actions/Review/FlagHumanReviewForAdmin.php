<?php

namespace App\Actions\Review;

use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Exceptions\HumanReviewException;
use App\Models\HumanReview;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\RequestBlockManager;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class FlagHumanReviewForAdmin
{
    public function __construct(private RequestBlockManager $blocks) {}

    public function execute(
        HumanReview $review,
        User $actor,
        HumanReviewStatus $decision,
        string $reason,
        ?string $correlationId = null,
    ): PlanningRequest {
        if (! in_array($decision, [HumanReviewStatus::Escalated, HumanReviewStatus::Rejected], true)) {
            throw new HumanReviewException('HUMAN_REVIEW_ADMIN_DECISION_INVALID');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new HumanReviewException('HUMAN_REVIEW_ADMIN_REASON_REQUIRED');
        }
        if (mb_strlen($reason) > 8000) {
            throw new HumanReviewException('HUMAN_REVIEW_ADMIN_REASON_TOO_LONG');
        }
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new HumanReviewException('HUMAN_REVIEW_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        return DB::transaction(function () use ($review, $actor, $decision, $reason, $correlationId): PlanningRequest {
            $locked = HumanReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $assignment = $locked->assignment()->lockForUpdate()->firstOrFail();
            $request = $locked->request()->lockForUpdate()->firstOrFail();

            if ($actor->status !== 'active'
                || ! $actor->hasVerifiedEmail()
                || ! $actor->hasRole(RoleCode::Reviewer)
                || (int) $locked->reviewer_id !== (int) $actor->id
                || (int) $assignment->reviewer_id !== (int) $actor->id) {
                throw new HumanReviewException('HUMAN_REVIEW_REVIEWER_FORBIDDEN');
            }

            if ($locked->status === $decision) {
                return $request->fresh();
            }
            if ($locked->status !== HumanReviewStatus::InProgress
                || $assignment->status !== ReviewAssignmentStatus::InProgress
                || $request->status !== PlanningRequestStatus::REVISION_HUMANA) {
                throw new HumanReviewException('HUMAN_REVIEW_ADMIN_DECISION_STATE_INVALID');
            }

            $now = now();
            $locked->forceFill([
                'status' => $decision->value,
                'decided_at' => $now,
                'general_comment' => $reason,
            ])->save();
            $assignment->forceFill([
                'status' => ReviewAssignmentStatus::Cancelled->value,
                'ended_at' => $now,
                'ended_by' => $actor->id,
                'ended_reason' => 'human_review_' . $decision->value,
            ])->save();

            $this->blocks->open($request, 'human_review_attention', 'human_review', [
                'decision' => $decision->value,
                'review_id' => (int) $locked->id,
                'assignment_id' => (int) $assignment->id,
                'reviewer_id' => (int) $actor->id,
                'reason' => $reason,
            ], $correlationId);

            return $request->fresh();
        }, attempts: 3);
    }
}
