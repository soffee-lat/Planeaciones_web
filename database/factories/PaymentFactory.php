<?php

namespace Database\Factories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Payment>
 */
class PaymentFactory extends Factory
{
    protected $model = Payment::class;

    public function definition(): array
    {
        return [
            'order_id' => Order::factory(),
            'customer_id' => function (array $attrs) {
                return Order::find($attrs['order_id'])->customer_id;
            },
            'provider' => 'manual',
            'provider_reference' => 'ref_' . Str::uuid()->toString(),
            'method' => 'transfer',
            'amount_minor' => 12000,
            'currency' => 'MXN',
            'status' => PaymentStatus::Succeeded->value,
            'occurred_at' => now(),
            'confirmed_by' => null,
        ];
    }
}
