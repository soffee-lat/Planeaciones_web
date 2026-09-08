<?php

namespace App\Actions\Commerce;

use App\Enums\SubscriptionPeriodStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Abre un nuevo SubscriptionPeriod tomando la PlanVersion actual de la
 * Subscription y congelando su entitlement en el snapshot.
 *
 * Reglas verbatim DATABASE.md:
 *   - Ventana [starts_at, ends_at).
 *   - No solape con otros periodos pending|active de la misma subscription
 *     (garantizado por EXCLUDE constraint subscription_periods_no_overlap).
 *   - entitlement_snapshot es inmutable (Filament no permite editarlo).
 *
 * El status resultante es 'active' si starts_at <= now < ends_at, en otro
 * caso 'pending'. En 3B admin puede pasar cualquier ventana; los tests
 * usan ventanas contemporáneas para probar reservas.
 */
class OpenSubscriptionPeriod
{
    public function __invoke(Subscription $subscription, CarbonInterface $startsAt, CarbonInterface $endsAt): SubscriptionPeriod
    {
        if ($endsAt->lte($startsAt)) {
            throw new RuntimeException('SUBSCRIPTION_PERIOD_INVALID_RANGE');
        }

        return DB::transaction(function () use ($subscription, $startsAt, $endsAt): SubscriptionPeriod {
            /** @var Subscription|null $fresh */
            $fresh = Subscription::query()->whereKey($subscription->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('SUBSCRIPTION_NOT_FOUND');
            }
            if (! $fresh->isOperational()) {
                throw new RuntimeException('SUBSCRIPTION_NOT_OPERATIONAL');
            }

            $planVersion = $fresh->planVersion;
            $snapshot = [
                'plan_version_id' => $planVersion->id,
                'plan_version_number' => $planVersion->number,
                'plan_version_checksum' => $planVersion->checksum,
                'max_planning_days' => (int) $planVersion->max_planning_days,
                'planning_limit' => (int) $planVersion->planning_limit,
                'human_review_limit' => (int) $planVersion->human_review_limit,
                'correction_limit' => (int) $planVersion->correction_limit,
                'group_limit' => (int) $planVersion->group_limit,
                'correction_window_days' => (int) $planVersion->correction_window_days,
                'sla_hours' => (int) $planVersion->sla_hours,
                'human_review_required' => (bool) $planVersion->human_review_required,
            ];

            $now = now();
            $status = ($startsAt->lte($now) && $endsAt->gt($now))
                ? SubscriptionPeriodStatus::Active
                : SubscriptionPeriodStatus::Pending;

            return SubscriptionPeriod::query()->create([
                'subscription_id' => $fresh->id,
                'plan_id' => $fresh->plan_id,
                'plan_version_id' => $planVersion->id,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'status' => $status->value,
                'entitlement_snapshot' => $snapshot,
                'paid_order_id' => null,
            ]);
        });
    }
}
