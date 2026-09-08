<?php

namespace Database\Factories;

use App\Enums\PaymentEventStatus;
use App\Models\PaymentEvent;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<PaymentEvent>
 */
class PaymentEventFactory extends Factory
{
    protected $model = PaymentEvent::class;

    public function definition(): array
    {
        $payload = ['ref' => Str::uuid()->toString()];
        return [
            'provider' => 'manual',
            'event_id' => 'evt_' . Str::uuid()->toString(),
            'payload_hash' => hash('sha256', json_encode($payload) ?: ''),
            'status' => PaymentEventStatus::Received->value,
            'received_at' => now(),
            'processed_at' => null,
            'error_code' => null,
        ];
    }
}
