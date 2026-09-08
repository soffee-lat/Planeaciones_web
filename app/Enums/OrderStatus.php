<?php

namespace App\Enums;

/**
 * Estados de una Order (DATABASE.md § orders).
 *
 * DATABASE.md no enumera literalmente los valores; los derivamos del flujo
 * WORKFLOWS.md ("Crear pedido → confirmación confiable → registrar pago →
 * activar periodo una sola vez") y del modelo de refunds.
 *
 * Transiciones permitidas en 3C:
 *   pending → paid
 *   pending → cancelled
 *   paid → refunded          (sólo si un Refund succeeded cubre el total)
 *
 * NO permitidas: paid → pending, paid → cancelled, refunded → *.
 */
enum OrderStatus: string
{
    case Pending = 'pending';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Refunded = 'refunded';

    public function isTerminal(): bool
    {
        return $this === self::Cancelled || $this === self::Refunded;
    }
}
