<?php

namespace Database\Factories;

use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Subscription>
 */
class SubscriptionFactory extends Factory
{
    protected $model = Subscription::class;

    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'plan_id' => function () {
                return Plan::factory()->create()->id;
            },
            'plan_version_id' => function (array $attrs) {
                // Publica una plan_version del plan_id ya elegido.
                $pv = PlanVersion::factory()->create(['plan_id' => $attrs['plan_id']]);
                (new \App\Actions\Plans\PublishPlanVersion())($pv, User::factory()->create());
                return $pv->id;
            },
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now(),
            'renews_at' => null,
            'cancel_requested_at' => null,
            'ends_at' => null,
        ];
    }

    public function forPlanVersion(PlanVersion $planVersion): self
    {
        return $this->state([
            'plan_id' => $planVersion->plan_id,
            'plan_version_id' => $planVersion->id,
        ]);
    }
}
