<?php

namespace App\Actions\Commerce;

use App\Enums\UsageReservationStatus;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Transiciona una reserva `reserved` → `released`.
 *
 * - Devuelve las unidades al pool `available` (deja de restar en reserved).
 * - NO acepta `consumed` → `released`: DATABASE.md deja las compensaciones
 *   para acciones administrativas explícitas en fases posteriores.
 * - Idempotente: si ya está released, devuelve sin cambios.
 */
class ReleasePlanningReservation
{
    public function __invoke(UsageReservation $reservation): UsageReservation
    {
        return DB::transaction(function () use ($reservation): UsageReservation {
            /** @var UsageReservation|null $fresh */
            $fresh = UsageReservation::query()->whereKey($reservation->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('USAGE_RESERVATION_NOT_FOUND');
            }
            if ($fresh->status === UsageReservationStatus::Released) {
                return $fresh;
            }
            if ($fresh->status === UsageReservationStatus::Consumed) {
                throw new RuntimeException('USAGE_RESERVATION_ALREADY_CONSUMED');
            }

            $fresh->forceFill([
                'status' => UsageReservationStatus::Released->value,
                'released_at' => now(),
            ])->save();

            return $fresh->fresh();
        });
    }
}
