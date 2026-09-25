<?php

namespace App\Actions\Commerce;

use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use App\Models\SubscriptionPeriod;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class GrantInternalUnlimitedMembership
{
    public function execute(User $actor, User $customer): SubscriptionPeriod
    {
        $planVersion = app(EnsureInternalUnlimitedPlan::class)->execute($actor);

        return DB::transaction(function () use ($customer, $planVersion): SubscriptionPeriod {
            $operational = Subscription::query()
                ->where('customer_id', $customer->id)
                ->whereIn('status', SubscriptionStatus::operationalValues())
                ->with('plan')
                ->lockForUpdate()
                ->first();

            if ($operational && $operational->plan?->code !== EnsureInternalUnlimitedPlan::PLAN_CODE) {
                throw new RuntimeException('INTERNAL_UNLIMITED_CONFLICTING_SUBSCRIPTION');
            }

            if (! $operational) {
                $operational = app(CreateSubscription::class)(
                    $customer,
                    $planVersion,
                    now()->subMinute(),
                );
            }

            $current = $operational->currentPeriod();
            if ($current) {
                return $current;
            }

            // Una membresía interna se concede para uso inmediato. Si existe una
            // suscripción interna sin periodo vigente, puede tratarse de una
            // concesión anterior creada con una sesión PostgreSQL en otra zona
            // horaria. Reparamos sólo este plan interno: normalizamos el inicio,
            // cancelamos periodos no vigentes y abrimos uno nuevo en UTC.
            if ($operational->starts_at?->gt(now())) {
                $operational->forceFill(['starts_at' => now()->subMinute()])->save();
            }

            $operational->periods()
                ->whereIn('status', ['pending', 'active'])
                ->update(['status' => 'cancelled']);

            return app(OpenSubscriptionPeriod::class)(
                $operational->fresh(),
                now()->subMinute(),
                now()->addYears(10),
            );
        }, attempts: 3);
    }
}
