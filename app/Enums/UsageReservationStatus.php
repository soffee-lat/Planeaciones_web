<?php

namespace App\Enums;

/**
 * Verbatim DATABASE.md § usage_reservations:
 *   status (reserved/consumed/released)
 *
 * Transiciones permitidas en 3B:
 *   reserved → consumed (irreversible)
 *   reserved → released (irreversible)
 * consumed → released NO está permitido en 3B (compensaciones se
 * modelarán en fases posteriores con ajuste administrativo explícito).
 */
enum UsageReservationStatus: string
{
    case Reserved = 'reserved';
    case Consumed = 'consumed';
    case Released = 'released';
}
