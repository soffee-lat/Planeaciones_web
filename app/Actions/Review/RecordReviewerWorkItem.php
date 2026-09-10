<?php

namespace App\Actions\Review;

use App\Enums\ApprovalKind;
use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewerWorkItemStatus;
use App\Exceptions\ReviewerCompensationException;
use App\Models\Approval;
use App\Models\HumanReview;
use App\Models\ReviewerWorkItem;
use Illuminate\Support\Facades\DB;

final class RecordReviewerWorkItem
{
    public function execute(HumanReview $review): ReviewerWorkItem
    {
        return DB::transaction(function () use ($review): ReviewerWorkItem {
            $locked = HumanReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $assignment = $locked->assignment()->lockForUpdate()->firstOrFail();
            $request = $locked->request()->lockForUpdate()->firstOrFail();

            $existing = ReviewerWorkItem::query()
                ->where('request_id', $request->id)
                ->where('cycle', $assignment->cycle)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            if ($locked->status !== HumanReviewStatus::Approved
                || $assignment->status !== ReviewAssignmentStatus::Completed
                || $assignment->ended_reason !== 'human_review_approved'
                || $request->status !== PlanningRequestStatus::APROBADA) {
                throw new ReviewerCompensationException('REVIEWER_WORK_ITEM_REVIEW_NOT_APPROVED');
            }

            $hasApproval = Approval::query()
                ->where('request_id', $request->id)
                ->where('version_id', $locked->version_id)
                ->where('kind', ApprovalKind::Human->value)
                ->where('review_id', $locked->id)
                ->exists();
            if (! $hasApproval) {
                throw new ReviewerCompensationException('REVIEWER_WORK_ITEM_HUMAN_APPROVAL_REQUIRED');
            }

            return ReviewerWorkItem::query()->create([
                'request_id' => $request->id,
                'review_id' => $locked->id,
                'assignment_id' => $assignment->id,
                'reviewer_id' => $assignment->reviewer_id,
                'cycle' => $assignment->cycle,
                'rate_minor' => $assignment->rate_snapshot_minor,
                'quantity' => $assignment->units_snapshot,
                'total_minor' => $assignment->total_fee_minor,
                'currency' => $assignment->currency,
                'status' => ReviewerWorkItemStatus::Approved->value,
                'approved_at' => $locked->decided_at ?? now(),
                'paid_at' => null,
                'settlement_id' => null,
            ])->fresh();
        }, attempts: 3);
    }
}
