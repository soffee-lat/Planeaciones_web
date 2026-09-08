<?php

namespace App\Enums;

/**
 * DATABASE.md § refunds no enumera literales. Usamos el conjunto mínimo:
 *   pending    — refund iniciado (para futuros gateways asíncronos)
 *   succeeded  — refund confirmado; suma contra payment.amount_minor
 *   failed     — refund rechazado por el proveedor
 *   cancelled  — refund cancelado antes de confirmarse
 *
 * En 3C con ManualPaymentGateway el flujo típico crea directamente refunds
 * en status=succeeded (el admin registra un ajuste ya realizado).
 */
enum RefundStatus: string
{
    case Pending = 'pending';
    case Succeeded = 'succeeded';
    case Failed = 'failed';
    case Cancelled = 'cancelled';
}
