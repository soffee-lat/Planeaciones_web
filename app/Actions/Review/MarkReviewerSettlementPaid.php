<?php

namespace App\Actions\Review;

use App\Enums\ReviewerSettlementStatus;
use App\Enums\ReviewerWorkItemStatus;
use App\Enums\RoleCode;
use App\Exceptions\ReviewerCompensationException;
use App\Models\ReviewerSettlement;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class MarkReviewerSettlementPaid
{
    public function execute(ReviewerSettlement $settlement, User $actor, string $reference): ReviewerSettlement
    {
        $this->assertAdmin($actor);
        $reference = trim($reference);
        if ($reference === '' || mb_strlen($reference) > 255) {
            throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_REFERENCE_INVALID');
        }

        return DB::transaction(function () use ($settlement, $actor, $reference): ReviewerSettlement {
            $locked = ReviewerSettlement::query()->whereKey($settlement->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ReviewerSettlementStatus::Paid) {
                if ($locked->reference !== $reference) {
                    throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_PAYMENT_CONFLICT');
                }
                return $locked->fresh('items');
            }
            if ($locked->status !== ReviewerSettlementStatus::Approved) {
                throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_NOT_APPROVED');
            }

            $items = $locked->items()->lockForUpdate()->get();
            if ($items->isEmpty() || $items->contains(fn ($item): bool => $item->status !== ReviewerWorkItemStatus::Approved)) {
                throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_ITEMS_NOT_APPROVED');
            }

            $paidAt = now();
            $locked->forceFill([
                'status' => ReviewerSettlementStatus::Paid->value,
                'reference' => $reference,
                'paid_by' => $actor->id,
                'paid_at' => $paidAt,
            ])->save();

            foreach ($items as $item) {
                $item->forceFill([
                    'status' => ReviewerWorkItemStatus::Paid->value,
                    'paid_at' => $paidAt,
                ])->save();
            }

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
