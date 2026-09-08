<?php

namespace App\Actions\Commerce;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\RefundStatus;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Registra una devolución manual sobre un Payment `succeeded`.
 *
 * Reglas verbatim DATABASE.md § refunds:
 *   "Suma confirmada <= pago bajo bloqueo; devoluciones parciales admitidas."
 *
 * Implementación:
 *   - `SELECT ... FOR UPDATE` del Payment para serializar el cálculo del
 *     total refundado con otros refunds en vuelo.
 *   - Suma `SUM(refunds.amount_minor WHERE status='succeeded')` + monto nuevo
 *     no puede exceder `payments.amount_minor`.
 *   - Idempotencia por `refunds.idempotency_key` UNIQUE.
 *   - Si el refund iguala el total del pago Y la Order sigue `paid`, la Order
 *     transiciona a `refunded`.
 *   - NUNCA devuelve unidades del ledger. Verbatim brief: "Un Refund NO debe
 *     automáticamente devolver unidades consumidas".
 */
class RecordManualRefund
{
    public function __invoke(
        Payment $payment,
        int $amountMinor,
        string $reason,
        string $idempotencyKey,
        User $confirmedBy,
        ?string $providerReference = null,
    ): Refund {
        if ($amountMinor <= 0) {
            throw new RuntimeException('REFUND_INVALID_AMOUNT');
        }

        return DB::transaction(function () use ($payment, $amountMinor, $reason, $idempotencyKey, $confirmedBy, $providerReference): Refund {
            /** @var Refund|null $existing */
            $existing = Refund::query()->where('idempotency_key', $idempotencyKey)->lockForUpdate()->first();
            if ($existing) {
                if (
                    $existing->payment_id !== $payment->id
                    || (int) $existing->amount_minor !== $amountMinor
                ) {
                    throw new RuntimeException('REFUND_IDEMPOTENCY_CONFLICT');
                }
                return $existing;
            }

            /** @var Payment|null $lockedPayment */
            $lockedPayment = Payment::query()->whereKey($payment->id)->lockForUpdate()->first();
            if (! $lockedPayment) {
                throw new RuntimeException('PAYMENT_NOT_FOUND');
            }
            if ($lockedPayment->status !== PaymentStatus::Succeeded) {
                throw new RuntimeException('PAYMENT_NOT_SUCCEEDED');
            }

            $alreadyRefunded = (int) $lockedPayment->refunds()
                ->where('status', RefundStatus::Succeeded->value)
                ->sum('amount_minor');
            if ($alreadyRefunded + $amountMinor > (int) $lockedPayment->amount_minor) {
                throw new RuntimeException('REFUND_EXCEEDS_PAYMENT');
            }

            $now = now();
            $refund = Refund::query()->create([
                'payment_id' => $lockedPayment->id,
                'amount_minor' => $amountMinor,
                'status' => RefundStatus::Succeeded->value,
                'reason' => $reason,
                'provider_reference' => $providerReference,
                'idempotency_key' => $idempotencyKey,
                'completed_at' => $now,
                'confirmed_by' => $confirmedBy->id,
            ]);

            // Si con este refund el pago quedó totalmente devuelto y la Order
            // sigue en `paid`, se marca como `refunded`.
            $totalRefunded = $alreadyRefunded + $amountMinor;
            if ($totalRefunded === (int) $lockedPayment->amount_minor) {
                $order = $lockedPayment->order()->lockForUpdate()->first();
                if ($order && $order->status === OrderStatus::Paid) {
                    $order->forceFill(['status' => OrderStatus::Refunded->value])->save();
                }
            }

            return $refund;
        });
    }
}
