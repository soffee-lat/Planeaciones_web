<?php

namespace App\Actions\Commerce;

use App\Enums\RoleCode;
use App\Enums\SubscriptionPeriodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class RevokeInternalUnlimitedMembership
{
    public function execute(User $actor, User $customer): void
    {
        if (! $actor->hasRole(RoleCode::Administrator)) {
            throw new RuntimeException('ADMIN_REQUIRED');
        }

        DB::transaction(function () use ($customer): void {
            $subscription = Subscription::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', SubscriptionStatus::operationalValues())
                ->whereHas('plan', fn ($query) => $query->where('code', EnsureInternalUnlimitedPlan::PLAN_CODE))
                ->lockForUpdate()
                ->first();

            if (! $subscription) {
                return;
            }

            $subscription->periods()
                ->whereIn('status', [
                    SubscriptionPeriodStatus::Pending->value,
                    SubscriptionPeriodStatus::Active->value,
                ])
                ->update(['status' => SubscriptionPeriodStatus::Cancelled->value]);

            $subscription->forceFill([
                'status' => SubscriptionStatus::Cancelled->value,
                'ends_at' => now(),
            ])->save();
        });
    }
}
