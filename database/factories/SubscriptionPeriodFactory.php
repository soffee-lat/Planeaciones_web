<?php

namespace Database\Factories;

use App\Enums\SubscriptionPeriodStatus;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SubscriptionPeriod>
 */
class SubscriptionPeriodFactory extends Factory
{
    protected $model = SubscriptionPeriod::class;

    public function definition(): array
    {
        $subscription = Subscription::factory()->create();
        return $this->attributesForSubscription($subscription);
    }

    public function forSubscription(Subscription $subscription): self
    {
        return $this->state(fn () => $this->attributesForSubscription($subscription));
    }

    /** @return array<string,mixed> */
    private function attributesForSubscription(Subscription $subscription): array
    {
        /** @var PlanVersion $pv */
        $pv = $subscription->planVersion;
        return [
            'subscription_id' => $subscription->id,
            'plan_id' => $subscription->plan_id,
            'plan_version_id' => $pv->id,
            'starts_at' => now()->subDay(),
            'ends_at' => now()->addDays(30),
            'status' => SubscriptionPeriodStatus::Active->value,
            'entitlement_snapshot' => [
                'plan_version_id' => $pv->id,
                'plan_version_number' => $pv->number,
                'plan_version_checksum' => $pv->checksum,
                'max_planning_days' => (int) $pv->max_planning_days,
                'planning_limit' => (int) $pv->planning_limit,
                'human_review_limit' => (int) $pv->human_review_limit,
                'correction_limit' => (int) $pv->correction_limit,
                'group_limit' => (int) $pv->group_limit,
                'correction_window_days' => (int) $pv->correction_window_days,
                'sla_hours' => (int) $pv->sla_hours,
                'human_review_required' => (bool) $pv->human_review_required,
            ],
            'paid_order_id' => null,
        ];
    }
}
