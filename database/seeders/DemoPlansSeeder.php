<?php

namespace Database\Seeders;

use App\Actions\Plans\PublishPlanVersion;
use App\Enums\RoleCode;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\User;
use Illuminate\Database\Seeder;

/**
 * Seeds DEMO idempotentes: BASIC-DEMO y REVIEWED-DEMO. Reglas exactas del
 * MVP.md § planes de referencia. Todos los datos comerciales son ficticios.
 */
class DemoPlansSeeder extends Seeder
{
    public function run(): void
    {
        $basic = Plan::query()->firstOrCreate(
            ['code' => 'BASIC-DEMO'],
            ['name' => 'Plan BASIC DEMO', 'description' => 'Datos ficticios para pruebas.', 'active' => true],
        );
        $reviewed = Plan::query()->firstOrCreate(
            ['code' => 'REVIEWED-DEMO'],
            ['name' => 'Plan REVIEWED DEMO', 'description' => 'Datos ficticios para pruebas.', 'active' => true],
        );

        $this->ensurePublishedVersion($basic, [
            'max_planning_days' => 7,
            'planning_limit' => 4,
            'human_review_limit' => 0,
            'correction_limit' => 1,
            'group_limit' => 1,
            'human_review_required' => false,
            'price_minor' => 0,
        ]);

        $this->ensurePublishedVersion($reviewed, [
            'max_planning_days' => 7,
            'planning_limit' => 4,
            'human_review_limit' => 4,
            'correction_limit' => 2,
            'group_limit' => 2,
            'human_review_required' => true,
            'price_minor' => 0,
        ]);
    }

    protected function ensurePublishedVersion(Plan $plan, array $overrides): void
    {
        if ($plan->versions()->whereNotNull('published_at')->exists()) {
            return;
        }

        $version = PlanVersion::query()->create(array_merge([
            'plan_id' => $plan->id,
            'number' => 1,
            'currency' => 'MXN',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'correction_window_days' => 14,
            'sla_hours' => 48,
        ], $overrides));

        $admin = User::query()
            ->whereHas('roles', fn ($q) => $q->where('code', RoleCode::Administrator->value))
            ->first();

        if (! $admin) {
            $admin = User::factory()->withRole(RoleCode::Administrator)->create();
        }

        (new PublishPlanVersion())($version, $admin);
    }
}
