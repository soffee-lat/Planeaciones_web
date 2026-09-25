<?php

namespace App\Actions\Commerce;

use App\Actions\Plans\PublishPlanVersion;
use App\Enums\RoleCode;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use RuntimeException;

final class EnsureInternalUnlimitedPlan
{
    public const PLAN_CODE = 'INTERNAL-UNLIMITED';

    public function execute(User $actor): PlanVersion
    {
        if (! $actor->hasRole(RoleCode::Administrator)) {
            throw new RuntimeException('ADMIN_REQUIRED');
        }

        $plan = Plan::query()->firstOrCreate(
            ['code' => self::PLAN_CODE],
            [
                'name' => 'Membresía interna ilimitada',
                'description' => 'Acceso interno para pruebas de producción. No es un plan comercial público.',
                'active' => true,
            ],
        );

        if (! $plan->active) {
            $plan->forceFill(['active' => true])->save();
        }

        $published = $plan->publishedVersions()->orderByDesc('number')->first();
        if ($published) {
            return $published;
        }

        $version = $plan->versions()->where('number', 1)->first();

        if (! $version) {
            $version = PlanVersion::query()->create([
                'plan_id' => $plan->id,
                'number' => 1,
                'price_minor' => 0,
                'currency' => 'MXN',
                'interval_unit' => 'year',
                'interval_count' => 10,
                'max_planning_days' => 3650,
                'planning_limit' => 1000000,
                'human_review_limit' => 1000000,
                'correction_limit' => 1000,
                'group_limit' => 100000,
                'correction_window_days' => 3650,
                'sla_hours' => 1,
                'human_review_required' => false,
                'features' => [
                    'internal_unlimited' => true,
                    'admin_granted' => true,
                ],
                'effective_from' => null,
                'effective_until' => null,
            ]);
        }

        return app(PublishPlanVersion::class)($version, $actor);
    }
}
