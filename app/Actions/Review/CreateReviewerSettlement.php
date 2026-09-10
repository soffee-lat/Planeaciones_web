<?php

namespace App\Actions\Review;

use App\Enums\ReviewerSettlementStatus;
use App\Enums\ReviewerWorkItemStatus;
use App\Enums\RoleCode;
use App\Exceptions\ReviewerCompensationException;
use App\Models\ReviewerProfile;
use App\Models\ReviewerSettlement;
use App\Models\ReviewerWorkItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class CreateReviewerSettlement
{
    public function execute(User $reviewer, User $actor, string $currency = 'MXN'): ReviewerSettlement
    {
        $this->assertAdmin($actor);
        $currency = strtoupper(trim($currency));
        if (! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_CURRENCY_INVALID');
        }

        return DB::transaction(function () use ($reviewer, $actor, $currency): ReviewerSettlement {
            ReviewerProfile::query()->where('user_id', $reviewer->id)->lockForUpdate()->firstOrFail();

            $active = ReviewerSettlement::query()
                ->where('reviewer_id', $reviewer->id)
                ->where('currency', $currency)
                ->whereIn('status', [ReviewerSettlementStatus::Draft->value, ReviewerSettlementStatus::Approved->value])
                ->lockForUpdate()
                ->first();
            if ($active) {
                return $active->fresh('items');
            }

            $items = ReviewerWorkItem::query()
                ->where('reviewer_id', $reviewer->id)
                ->where('currency', $currency)
                ->where('status', ReviewerWorkItemStatus::Approved->value)
                ->whereNull('settlement_id')
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            if ($items->isEmpty()) {
                throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_NO_PAYABLE_ITEMS');
            }

            $settlement = ReviewerSettlement::query()->create([
                'reviewer_id' => $reviewer->id,
                'currency' => $currency,
                'status' => ReviewerSettlementStatus::Draft->value,
                'reference' => null,
                'approved_by' => null,
                'approved_at' => null,
                'paid_by' => null,
                'paid_at' => null,
            ]);

            foreach ($items as $item) {
                $item->forceFill(['settlement_id' => $settlement->id])->save();
            }

            return $settlement->fresh('items');
        }, attempts: 3);
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new ReviewerCompensationException('REVIEWER_SETTLEMENT_ADMIN_REQUIRED');
        }
    }
}
