<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Commerce\ReservePlanningUnits;
use App\Enums\UsageResource;
use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Feature\PedagogyTestCase;

/**
 * Estas pruebas verifican que las defensas de nivel BD (UNIQUE index sobre
 * operation_key + trigger de inmutabilidad + EXCLUDE sobre periodos)
 * bloquean carreras aun cuando la lógica aplicativa fuese burlada.
 *
 * NOTA: PHPUnit + RefreshDatabase envuelve el test en una transacción, lo
 * cual imposibilita usar dos conexiones simultáneas visibles entre sí. Estas
 * pruebas apuntan a los invariantes de esquema, no a paralelismo real. La
 * concurrencia real se logra en producción mediante `SELECT ... FOR UPDATE`
 * dentro de las Actions, cuya lógica está cubierta por los tests de
 * idempotencia en UsageLedgerTest.
 */
class UsageLedgerConcurrencyGuardsTest extends PedagogyTestCase
{
    use RefreshDatabase;

    public function test_operation_key_unique_index_blocks_duplicate_insertion_bypassing_action(): void
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id, 'planning_limit' => 4]);
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $customer = $this->customer();
        $sub = (new CreateSubscription())($customer, $pv->fresh(), now());
        $period = (new OpenSubscriptionPeriod())($sub, now()->subDay(), now()->addDays(30));

        (new ReservePlanningUnits())($period, UsageResource::Planning, 1, 'race-key');

        // Salteando la Action y forzando la inserción cruda: la UNIQUE debe fallar.
        $this->expectException(QueryException::class);
        DB::table('usage_reservations')->insert([
            'subscription_period_id' => $period->id,
            'planning_request_id' => null,
            'correction_request_id' => null,
            'resource' => UsageResource::Planning->value,
            'operation_key' => 'race-key', // duplicada
            'quantity' => 1,
            'status' => 'reserved',
            'reserved_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_terminal_state_cannot_be_mutated_at_db_level(): void
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id, 'planning_limit' => 4]);
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $sub = (new CreateSubscription())($this->customer(), $pv->fresh(), now());
        $period = (new OpenSubscriptionPeriod())($sub, now()->subDay(), now()->addDays(30));
        $r = (new ReservePlanningUnits())($period, UsageResource::Planning, 1, 'race-terminal');
        (new \App\Actions\Commerce\ConsumePlanningReservation())($r);

        $this->expectException(QueryException::class);
        DB::table('usage_reservations')->where('id', $r->id)->update([
            'status' => 'released',
            'released_at' => now(),
        ]);
    }
}
