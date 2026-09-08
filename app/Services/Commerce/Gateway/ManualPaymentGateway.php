<?php

namespace App\Services\Commerce\Gateway;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Gateway "manual" — registro administrativo de pagos confirmados fuera del
 * sistema (transferencia bancaria, efectivo verificado, POS externo).
 *
 * NO simula una integración con un banco. La UI que llama a este adaptador
 * debe indicar claramente al administrador que está registrando un pago ya
 * ocurrido, no cobrando en línea.
 *
 * Idempotencia (bloqueo bajo transacción):
 *   - `payments_provider_reference_uniq` en (provider,provider_reference).
 *   - `payments_order_succeeded_uniq` (un único succeeded por Order).
 *   - `orders.idempotency_key` no aplica aquí porque nace de la Order.
 *
 * Concurrencia: se hace `SELECT ... FOR UPDATE` de la Order dentro de una
 * transacción; dos administradores confirmando el mismo pago compiten por
 * el lock y sólo uno inserta el Payment; el otro lo recupera y termina no-op.
 */
class ManualPaymentGateway implements PaymentGateway
{
    public const CODE = 'manual';

    public function code(): string
    {
        return self::CODE;
    }

    public function recordManualPayment(Order $order, ManualPaymentInput $input, User $confirmedBy): Payment
    {
        return DB::transaction(function () use ($order, $input, $confirmedBy): Payment {
            /** @var Order|null $lockedOrder */
            $lockedOrder = Order::query()->whereKey($order->id)->lockForUpdate()->first();
            if (! $lockedOrder) {
                throw new RuntimeException('ORDER_NOT_FOUND');
            }

            // Idempotencia: mismo (provider, provider_reference) ya existente.
            /** @var Payment|null $existing */
            $existing = Payment::query()
                ->where('provider', self::CODE)
                ->where('provider_reference', $input->providerReference)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    $existing->order_id !== $lockedOrder->id
                    || (int) $existing->amount_minor !== $input->amountMinor
                    || strcasecmp($existing->currency, $input->currency) !== 0
                ) {
                    throw new RuntimeException('PAYMENT_IDEMPOTENCY_CONFLICT');
                }
                return $existing;
            }

            if ($lockedOrder->status->value === 'cancelled') {
                throw new RuntimeException('ORDER_CANCELLED_CANNOT_PAY');
            }
            if ($lockedOrder->status->value === 'refunded') {
                throw new RuntimeException('ORDER_REFUNDED_CANNOT_PAY');
            }
            if ((int) $lockedOrder->total_minor !== $input->amountMinor) {
                throw new RuntimeException('PAYMENT_AMOUNT_MISMATCH');
            }
            if (strcasecmp($lockedOrder->currency, $input->currency) !== 0) {
                throw new RuntimeException('PAYMENT_CURRENCY_MISMATCH');
            }

            $payment = Payment::query()->create([
                'order_id' => $lockedOrder->id,
                'customer_id' => $lockedOrder->customer_id,
                'provider' => self::CODE,
                'provider_reference' => $input->providerReference,
                'method' => $input->method,
                'amount_minor' => $input->amountMinor,
                'currency' => strtoupper($input->currency),
                'status' => PaymentStatus::Succeeded->value,
                'occurred_at' => $input->occurredAt,
                'confirmed_by' => $confirmedBy->id,
            ]);

            // Marca la Order como pagada si aún no lo estaba.
            if ($lockedOrder->status->value === 'pending') {
                $lockedOrder->forceFill([
                    'status' => 'paid',
                    'paid_at' => $input->occurredAt,
                ])->save();
            }

            return $payment;
        });
    }
}
