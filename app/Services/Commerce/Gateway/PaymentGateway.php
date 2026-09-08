<?php

namespace App\Services\Commerce\Gateway;

use App\Models\Order;
use App\Models\Payment;
use App\Models\User;

/**
 * Contrato de gateway de pagos.
 *
 * Diseñado según WORKFLOWS.md verbatim:
 *   PaymentGateway: createCheckout(order,idempotencyKey), getPayment(reference),
 *   verifyWebhook(headers,rawBody), refund(payment,amount,key). DTOs
 *   normalizados; controladores no deciden derechos.
 *
 * En 3C sólo se implementa la operación de registro manual (recordPayment)
 * y la interfaz mínima; createCheckout/verifyWebhook/refund se dejan sin
 * implementar en el adaptador manual y lanzarán excepción explícita si un
 * proveedor externo se añade en el futuro.
 *
 * NUNCA se llama desde Controllers ni Filament directamente; los consumidores
 * son las Actions `RecordManualPayment` y (en el futuro) `ConfirmWebhookEvent`.
 */
interface PaymentGateway
{
    /**
     * Identificador estable del proveedor (por ejemplo 'manual', 'stripe',
     * 'mercadopago'). Debe coincidir con el valor almacenado en
     * `payments.provider` y `payment_events.provider`.
     */
    public function code(): string;

    /**
     * Registra un pago exitoso ya realizado fuera de banda (transferencia,
     * efectivo verificado, POS externo). Es la única operación real en 3C.
     *
     * Idempotencia: si ya existe un Payment (`provider`, `provider_reference`)
     * asociado a la misma Order con el mismo payload, devuelve el existente.
     * Si el payload difiere, lanza PAYMENT_IDEMPOTENCY_CONFLICT.
     */
    public function recordManualPayment(Order $order, ManualPaymentInput $input, User $confirmedBy): Payment;
}
