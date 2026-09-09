<?php

namespace Database\Factories;

use App\Enums\ReviewAssignmentStatus;
use App\Models\PlanningRequest;
use App\Models\ReviewerAssignment;
use App\Models\ReviewerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewerAssignmentFactory extends Factory
{
    protected $model = ReviewerAssignment::class;

    public function definition(): array
    {
        $units = 1;
        $rate = 2500;

        return [
            'request_id' => fn () => PlanningRequest::factory()->create()->id,
            'reviewer_id' => fn () => ReviewerProfile::factory()->create()->user_id,
            'cycle' => 1,
            'status' => ReviewAssignmentStatus::Assigned->value,
            'due_at' => now()->addDays(2),
            'assigned_at' => now(),
            'started_at' => null,
            'ended_at' => null,
            'ended_by' => null,
            'ended_reason' => null,
            'rate_snapshot_minor' => $rate,
            'units_snapshot' => $units,
            'total_fee_minor' => $rate * $units,
            'currency' => 'MXN',
        ];
    }
}
