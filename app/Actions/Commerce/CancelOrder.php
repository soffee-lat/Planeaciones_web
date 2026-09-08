<?php

namespace App\Actions\Commerce;

use App\Enums\OrderStatus;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Cancela una Order en `pending`. Sólo permitida antes del pago.
 *
 * `paid` / `refunded` / `cancelled` son terminales (trigger BD
 * `order_paid_immutability_guard` bloquea las salidas).
 */
class CancelOrder
{
    public function __invoke(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor): Order {
            /** @var Order|null $fresh */
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }
            if ($fresh->status !== OrderStatus::Pending) {
                throw new RuntimeException('ORDER_NOT_CANCELLABLE');
            }
            $fresh->forceFill(['status' => OrderStatus::Cancelled->value])->save();
            return $fresh->fresh();
        });
    }
}
