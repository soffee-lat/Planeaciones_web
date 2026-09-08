<?php

namespace Database\Factories;

use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Refund>
 */
class RefundFactory extends Factory
{
    protected $model = Refund::class;

    public function definition(): array
    {
        return [
            'payment_id' => Payment::factory(),
            'amount_minor' => 12000,
            'status' => RefundStatus::Succeeded->value,
            'reason' => 'Ajuste administrativo',
            'provider_reference' => null,
            'idempotency_key' => (string) Str::uuid(),
            'completed_at' => now(),
            'confirmed_by' => null,
        ];
    }
}
