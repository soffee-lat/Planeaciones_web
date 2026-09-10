<?php

namespace App\Actions\Review;

use App\Enums\ReviewerSettlementStatus;
use App\Enums\ReviewerWorkItemStatus;
use App\Enums\RoleCode;
use App\Exceptions\ReviewerCompensationException;
use App\Models\ReviewerSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class ApproveReviewerSettlement
{
    public function execute(ReviewerSettlement $settlement, User $actor): ReviewerSettlement
    {
        $this->assertAdmin($actor);

        return DB::transaction(function () use ($settlement, $actor): ReviewerSettlement {
            $locked = ReviewerSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if (in_array($locked->status, [ReviewerSettlementStatus::Approved, ReviewerSettlementStatus::Paid], true)) {
                return $locked->fresh('items');
            }
            if ($locked->status !== ReviewerSettlementStatus::Draft) {
                throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_STATE_INVALID');
            }

            $items = $locked->items()->lockForUpdate()->get();
            if ($items->isEmpty() || $items->contains(fn ($item): bool => $item->status !== ReviewerWorkItemStatus::Approved)) {
                throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_ITEMS_NOT_APPROVED');
            }

            $locked->forceFill([
                'status' => ReviewerSettlementStatus::Approved->value,
                'approved_by' => $actor->id,
                'approved_at' => now(),
            ])->save();

            return $locked->fresh('items');
        }, attempts: 3);
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_ADMIN_REQUIRED');
        }
    }
}
