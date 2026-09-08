<?php

namespace App\Enums;

/**
 * Estados de un SubscriptionPeriod.
 *
 * DATABASE.md no enumera valores literales para el status de periodo; los
 * derivamos del ciclo de vida operativo: pendiente de activación (post-orden),
 * activo (consumible), terminado (fin de ventana) y cancelado (revocado antes
 * de activarse o durante la ventana por acción administrativa).
 */
enum SubscriptionPeriodStatus: string
{
    case Pending = 'pending';
    case Active = 'active';
    case Ended = 'ended';
    case Cancelled = 'cancelled';

    /** Sólo periodos activos aceptan nuevas reservas de unidades. */
    public function isReservable(): bool
    {
        return $this === self::Active;
    }
}
