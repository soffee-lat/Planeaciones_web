<?php

namespace App\Actions\Commerce;

use App\Enums\UsageReservationStatus;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transiciona una reserva `reserved` → `consumed`.
 *
 * - Idempotente: llamar sobre una reserva ya `consumed` devuelve la reserva
 *   sin cambios (misma quantity, mismo consumed_at). Llamar sobre `released`
 *   lanza USAGE_RESERVATION_ALREADY_RELEASED.
 * - No modifica available: las unidades ya estaban restadas del balance
 *   cuando estaban en `reserved` (available = limit - reserved - consumed).
 */
class ConsumePlanningReservation
{
    public function __invoke(UsageReservation $reservation): UsageReservation
    {
        return DB::transaction(function () use ($reservation): UsageReservation {
            /** @var UsageReservation|null $fresh */
            $fresh = UsageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('USAGE_RESERVATION_NOT_FOUND');
            }
            if ($fresh->status === UsageReservationStatus::Consumed) {
                return $fresh; // idempotente
            }
            if ($fresh->status === UsageReservationStatus::Released) {
                throw new RuntimeException('USAGE_RESERVATION_ALREADY_RELEASED');
            }

            $fresh->forceFill([
                'status' => UsageReservationStatus::Consumed->value,
                'consumed_at' => now(),
            ])->save();

            return $fresh->fresh();
        });
    }
}
