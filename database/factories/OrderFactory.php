<?php

namespace Database\Factories;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    protected $model = Order::class;

    public function definition(): array
    {
        return [
            'customer_id' => User::factory(),
            'plan_id' => function () {
                return Plan::factory()->create()->id;
            },
            'plan_version_id' => function (array $attrs) {
                $pv = PlanVersion::factory()->create(['plan_id' => $attrs['plan_id']]);
                (new \App\Actions\Plans\PublishPlanVersion())($pv, User::factory()->create());
                return $pv->id;
            },
            'concept' => 'Compra DEMO',
            'total_minor' => 12000,
            'currency' => 'MXN',
            'status' => OrderStatus::Pending->value,
            'idempotency_key' => (string) Str::uuid(),
            'paid_at' => null,
        ];
    }

    public function forPlanVersion(PlanVersion $planVersion): self
    {
        return $this->state(fn () => [
            'plan_id' => $planVersion->plan_id,
            'plan_version_id' => $planVersion->id,
            'total_minor' => (int) $planVersion->price_minor,
            'currency' => strtoupper((string) $planVersion->currency),
        ]);
    }
}
