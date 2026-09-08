<?php

namespace App\Enums;

/**
 * Estados del ciclo comercial de una Suscripción.
 *
 * Valores tomados verbatim de DATABASE.md § catálogo comercial:
 *   status (pending/active/past_due/cancelled/expired)
 *
 * Estados operativamente vigentes (cuentan para el índice único parcial de
 * "una suscripción vigente por cliente" y habilitan reservas de unidades):
 *   - active
 *   - past_due
 */
enum SubscriptionStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case PastDue = 'past_due';
    case Cancelled = 'cancelled';
    case Expired = 'expired';

    /**
     * Estados operativos: participan del bloqueo de unicidad parcial y son
     * elegibles para abrir/consumir periodos.
     */
    public function isOperational(): bool
    {
        return $this === self::Active || $this === self::PastDue;
    }

    /** @return array<int, string> */
    public static function operationalValues(): array
    {
        return [self::Active->value, self::PastDue->value];
    }
}
