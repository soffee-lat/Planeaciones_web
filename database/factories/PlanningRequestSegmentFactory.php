<?php

namespace Database\Factories;

use App\Models\PlanningRequestSegment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<PlanningRequestSegment>
 */
class PlanningRequestSegmentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'planning_request_id' => \App\Models\PlanningRequest::factory()->state([
                'status' => \App\Enums\PlanningRequestStatus::ESPERANDO_PAGO,
                'starts_on' => '2026-10-01', 'ends_on' => '2026-10-07',
            ]),
            'sequence' => 1,
            'starts_on' => '2026-10-01',
            'ends_on' => '2026-10-07',
            'calendar_days' => 7,
            'units' => 1,
        ];
    }
}
