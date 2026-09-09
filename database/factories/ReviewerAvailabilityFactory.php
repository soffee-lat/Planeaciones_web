<?php

namespace Database\Factories;

use App\Models\ReviewerAvailability;
use App\Models\ReviewerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewerAvailabilityFactory extends Factory
{
    protected $model = ReviewerAvailability::class;

    public function definition(): array
    {
        return [
            'reviewer_id' => fn () => ReviewerProfile::factory()->create()->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ];
    }
}
