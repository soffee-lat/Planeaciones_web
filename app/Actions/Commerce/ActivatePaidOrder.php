<?php

namespace App\Actions\Commerce;

use App\Enums\OrderStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Order;
use App\Models\PlanVersion;
use App\Models\Subscription;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Aplica comercialmente una Order pagada: crea la Subscription y abre el
 * primer SubscriptionPeriod congelando los límites de la PlanVersion exacta
 * comprada, y enlaza el periodo con la Order (`paid_order_id`).
 *
 * Idempotencia crítica (verbatim brief):
 *   "Reprocesar una Order pagada NO debe crear segunda Subscription, segundo
 *    SubscriptionPeriod ni duplicar derechos."
 *
 * Implementación:
 *   - `orders.activated_subscription_id` guarda la Subscription resultante.
 *   - Si ya está seteado, la Action devuelve la Subscription existente sin
 *     efectos secundarios.
 *   - Si el cliente ya tiene otra Subscription operativa (`active|past_due`)
 *     distinta, se lanza `CUSTOMER_ALREADY_HAS_ACTIVE_SUBSCRIPTION`. NO se
 *     hace upgrade ni cambio automático (política 3C).
 *
 * Vigencia del primer periodo:
 *   [pagoAt, pagoAt + interval_count * {month|year}) — semántica calendarizada
 *   Carbon; no aproxima mes = 30 días.
 */
class ActivatePaidOrder
{
    public function __construct(
        private readonly CreateSubscription $createSubscription = new CreateSubscription(),
        private readonly OpenSubscriptionPeriod $openSubscriptionPeriod = new OpenSubscriptionPeriod(),
    ) {
    }

    public function __invoke(Order $order): Subscription
    {
        return DB::transaction(function () use ($order): Subscription {
            /** @var Order|null $fresh */
            $fresh = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }
            if ($fresh->status !== OrderStatus::Paid && $fresh->status !== OrderStatus::Refunded) {
                throw new RuntimeException('ORDER_NOT_PAID');
            }

            // Idempotencia: si ya fue aplicada, devolver Subscription previa.
            if ($fresh->activated_subscription_id !== null) {
                /** @var Subscription $existing */
                $existing = Subscription::query()->whereKey($fresh->activated_subscription_id)->firstOrFail();
                return $existing;
            }

            // Bloqueo de usuario para evitar carreras con otra Order en paralelo.
            DB::table('users')->where('id', $fresh->customer_id)->lockForUpdate()->first();

            $customer = $fresh->customer;

            // Rechazar si ya existe otra Subscription operativa del cliente.
            $conflicting = Subscription::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', SubscriptionStatus::operationalValues())
                ->lockForUpdate()
                ->first();
            if ($conflicting) {
                throw new RuntimeException('CUSTOMER_ALREADY_HAS_ACTIVE_SUBSCRIPTION');
            }

            /** @var PlanVersion $pv */
            $pv = $fresh->planVersion;
            $startsAt = $fresh->paid_at ?? now();
            $endsAt = $this->calculatePeriodEnd($pv, $startsAt);

            $subscription = ($this->createSubscription)($customer, $pv, $startsAt);
            $period = ($this->openSubscriptionPeriod)($subscription, $startsAt, $endsAt);

            // Cerrar el ciclo: link Order↔Subscription y Period↔Order.
            $fresh->forceFill([
                'activated_subscription_id' => $subscription->id,
                'subscription_period_id' => $period->id,
            ])->save();
            $period->forceFill(['paid_order_id' => $fresh->id])->save();

            return $subscription->refresh();
        });
    }

    private function calculatePeriodEnd(PlanVersion $planVersion, CarbonInterface $startsAt): CarbonInterface
    {
        $count = max(1, (int) $planVersion->interval_count);
        return match ($planVersion->interval_unit) {
            'year' => $startsAt->copy()->addYears($count),
            default => $startsAt->copy()->addMonths($count),
        };
    }
}
