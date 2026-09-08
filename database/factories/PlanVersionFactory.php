<?php

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanVersion>
 */
class PlanVersionFactory extends Factory
{
    protected $model = PlanVersion::class;

    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'number' => 1,
            'price_minor' => 12000, // 120.00 MXN — ficticio
            'currency' => 'MXN',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'max_planning_days' => 7,
            'planning_limit' => 4,
            'human_review_limit' => 0,
            'correction_limit' => 1,
            'group_limit' => 1,
            'correction_window_days' => 14,
            'sla_hours' => 48,
            'human_review_required' => false,
            'features' => null,
            'effective_from' => null,
            'effective_until' => null,
            'published_at' => null,
            'published_by' => null,
            'checksum' => null,
        ];
    }

    public function reviewed(): self
    {
        return $this->state(fn () => [
            'human_review_required' => true,
            'human_review_limit' => 4,
            'correction_limit' => 2,
            'group_limit' => 2,
        ]);
    }

    public function published(?User $by = null): self
    {
        return $this->afterCreating(function (PlanVersion $v) use ($by) {
            $publisher = $by ?? User::factory()->create();
            (new \App\Actions\Plans\PublishPlanVersion())($v, $publisher);
        });
    }
}
