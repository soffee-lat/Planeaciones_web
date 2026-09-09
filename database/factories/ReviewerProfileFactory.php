<?php

namespace Database\Factories;

use App\Enums\ReviewerProfileStatus;
use App\Enums\RoleCode;
use App\Models\ReviewerProfile;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReviewerProfileFactory extends Factory
{
    protected $model = ReviewerProfile::class;

    public function definition(): array
    {
        return [
            'user_id' => fn () => User::factory()->withRole(RoleCode::Reviewer)->create()->id,
            'status' => ReviewerProfileStatus::Active->value,
            'max_load' => 8,
            'daily_max' => 8,
            'rate_minor' => 2500,
            'currency' => 'MXN',
        ];
    }
}
