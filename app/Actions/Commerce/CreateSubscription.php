<?php

namespace App\Actions\Commerce;

use App\Enums\SubscriptionStatus;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Crea una Subscription administrativa apuntando a una PlanVersion publicada.
 *
 * Reglas:
 *   - PlanVersion debe estar publicada (validado además por trigger BD).
 *   - Cliente no puede tener otra suscripción operativa (active|past_due)
 *     — validado por índice único parcial subscriptions_customer_operational_uniq
 *     y por comprobación explícita para reportar un código semántico.
 *   - Estado inicial: active (esta acción representa alta manual admin;
 *     el checkout público que crea `pending` se implementa en 3C).
 */
class CreateSubscription
{
    public function __invoke(User $customer, PlanVersion $planVersion, CarbonInterface $startsAt): Subscription
    {
        return DB::transaction(function () use ($customer, $planVersion, $startsAt): Subscription {
            // Lock del usuario para evitar carreras de creación paralela.
            DB::table('users')->where('id', $customer->id)->lockForUpdate()->first();

            /** @var PlanVersion|null $fresh */
            $fresh = PlanVersion::query()->whereKey($planVersion->id)->lockForUpdate()->first();
            if (! $fresh) {
                throw new RuntimeException('PLAN_VERSION_NOT_FOUND');
            }
            if ($fresh->published_at === null) {
                throw new RuntimeException('SUBSCRIPTION_PLAN_VERSION_NOT_PUBLISHED');
            }

            $existing = Subscription::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', SubscriptionStatus::operationalValues())
                ->lockForUpdate()
                ->first();
            if ($existing) {
                throw new RuntimeException('SUBSCRIPTION_ALREADY_OPERATIONAL');
            }

            return Subscription::query()->create([
                'customer_id' => $customer->id,
                'plan_id' => $fresh->plan_id,
                'plan_version_id' => $fresh->id,
                'status' => SubscriptionStatus::Active->value,
                'starts_at' => $startsAt,
            ]);
        });
    }
}
