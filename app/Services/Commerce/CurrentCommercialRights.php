<?php

namespace App\Services\Commerce;

use App\Enums\SubscriptionStatus;
use App\Enums\SubscriptionPeriodStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\User;

class CurrentCommercialRights
{
    /** Lock only inside the authorization transaction; previews are read-only. */
    public function forCustomer(User $customer, bool $lock = false): ?SubscriptionPeriod
    {
        $query = Subscription::query()->where('customer_id', $customer->id)
            ->where('status', SubscriptionStatus::Active)
            ->where('starts_at', '<=', now())
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', now()));
        $subscription = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $subscription) {
            return null;
        }
        $query = $subscription->periods()->where('status', SubscriptionPeriodStatus::Active)
            ->where('starts_at', '<=', now())->where('ends_at', '>', now());
        $period = ($lock ? $query->lockForUpdate() : $query)->first();
        if (! $period || $period->plan_version_id !== $subscription->plan_version_id) {
            return null;
        }
        $period->setRelation('subscription', $subscription);

        return $period;
    }
}
