<?php

namespace App\Actions\Commerce;

use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\SubscriptionPeriod;
use App\Models\UsageReservation;
use App\Services\Commerce\SubscriptionBalance;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Reserva `$units` unidades de un `UsageResource` contra el entitlement del
 * SubscriptionPeriod, con idempotencia por `operation_key`.
 *
 * Reglas:
 *   - Sólo periodos con status='active' y ventana temporal vigente aceptan reservas.
 *   - `planning` y `human_review` consumen contra planning_limit_snapshot /
 *     human_review_limit_snapshot; `client_correction` NO consume contra el
 *     periodo (excepción documentada en DATABASE.md).
 *   - Idempotencia: si ya existe una reserva con la misma `operation_key`, se
 *     devuelve la existente **si el payload es compatible** (mismo periodo,
 *     resource, quantity). Si difieren → USAGE_IDEMPOTENCY_CONFLICT.
 *   - Concurrencia: se hace `SELECT ... FOR UPDATE` sobre el period para
 *     serializar el cálculo de balance con otras reservas del mismo periodo.
 */
class ReservePlanningUnits
{
    public function __invoke(
        SubscriptionPeriod $period,
        UsageResource $resource,
        int $units,
        string $operationKey,
        ?int $planningRequestId = null,
    ): UsageReservation {
        if ($units <= 0) {
            throw new RuntimeException('USAGE_RESERVATION_INVALID_UNITS');
        }

        return DB::transaction(function () use ($period, $resource, $units, $operationKey, $planningRequestId): UsageReservation {
            // Idempotencia: si existe reserva con la misma clave, validar payload y devolver.
            $existing = UsageReservation::query()
                ->where('operation_key', $operationKey)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if (
                    $existing->subscription_period_id !== $period->id
                    || $existing->resource !== $resource
                    || (int) $existing->quantity !== $units
                ) {
                    throw new RuntimeException('USAGE_IDEMPOTENCY_CONFLICT');
                }
                return $existing;
            }

            /** @var SubscriptionPeriod|null $fresh */
            $fresh = SubscriptionPeriod::query()->whereKey($period->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('SUBSCRIPTION_PERIOD_NOT_FOUND');
            }
            if (! $fresh->isReservable()) {
                throw new RuntimeException('SUBSCRIPTION_PERIOD_NOT_ACTIVE');
            }

            if ($resource->consumesPeriodEntitlement()) {
                $limitKey = $resource === UsageResource::Planning ? 'planning_limit' : 'human_review_limit';
                $limit = $fresh->entitlement($limitKey);
                if ($limit <= 0 && $resource === UsageResource::HumanReview) {
                    throw new RuntimeException('HUMAN_REVIEW_NOT_INCLUDED');
                }
                $balances = (new SubscriptionBalance())->forPeriod($fresh);
                $balance = $resource === UsageResource::Planning ? $balances['planning'] : $balances['human_review'];
                if ($units > $balance->available()) {
                    throw new RuntimeException('INSUFFICIENT_PLANNING_UNITS');
                }
            }

            return UsageReservation::query()->create([
                'subscription_period_id' => $fresh->id,
                'planning_request_id' => $planningRequestId,
                'correction_request_id' => null,
                'resource' => $resource->value,
                'operation_key' => $operationKey,
                'quantity' => $units,
                'status' => UsageReservationStatus::Reserved->value,
                'reserved_at' => now(),
            ]);
        });
    }
}
