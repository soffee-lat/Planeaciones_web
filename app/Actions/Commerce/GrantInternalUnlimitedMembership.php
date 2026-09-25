<?php

namespace App\Actions\Commerce;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use RuntimeException;

final class GrantInternalUnlimitedMembership
{
    public function execute(User $actor, User $customer): SubscriptionPeriod
    {
        $planVersion = app(EnsureInternalUnlimitedPlan::class)->execute($actor);

        $operational = Subscription::query()
            ->where('customer_id', $customer->id)
            ->whereIn('status', SubscriptionStatus::operationalValues())
            ->with('plan')
            ->first();

        if ($operational && $operational->plan?->code !== EnsureInternalUnlimitedPlan::PLAN_CODE) {
            throw new RuntimeException('INTERNAL_UNLIMITED_CONFLICTING_SUBSCRIPTION');
        }

        if (! $operational) {
            $operational = app(CreateSubscription::class)(
                $customer,
                $planVersion,
                now()->subMinute(),
            );
        }

        $current = $operational->currentPeriod();
        if ($current) {
            return $current;
        }

        $overlapping = $operational->periods()
            ->whereIn('status', ['pending', 'active'])
            ->where('ends_at', '>', now())
            ->exists();

        if ($overlapping) {
            throw new RuntimeException('INTERNAL_UNLIMITED_PERIOD_CONFLICT');
        }

        return app(OpenSubscriptionPeriod::class)(
            $operational,
            now()->subMinute(),
            now()->addYears(10),
        );
    }
}
