<?php

namespace Database\Factories;

use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\SubscriptionPeriod;
use App\Models\UsageReservation;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<UsageReservation>
 */
class UsageReservationFactory extends Factory
{
    protected $model = UsageReservation::class;

    public function definition(): array
    {
        return [
            'subscription_period_id' => SubscriptionPeriod::factory(),
            'planning_request_id' => null,
            'correction_request_id' => null,
            'resource' => UsageResource::Planning->value,
            'operation_key' => 'op_' . Str::uuid()->toString(),
            'quantity' => 1,
            'status' => UsageReservationStatus::Reserved->value,
            'reserved_at' => now(),
            'consumed_at' => null,
            'released_at' => null,
        ];
    }
}
