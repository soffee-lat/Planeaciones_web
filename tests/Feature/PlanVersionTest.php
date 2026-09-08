<?php

namespace Tests\Feature;

use App\Actions\Plans\PublishPlanVersion;
use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\Test;
use RuntimeException;

class PlanVersionTest extends PedagogyTestCase
{
    use RefreshDatabase;

    #[Test]
    public function permite_crear_borrador_y_publicar_como_admin(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $version = PlanVersion::factory()->create(['plan_id' => $plan->id, 'number' => 1]);

        $this->assertTrue($version->isDraft());

        (new PublishPlanVersion())($version->fresh(), $admin);

        $version->refresh();
        $this->assertTrue($version->isPublished());
        $this->assertNotNull($version->checksum);
        $this->assertSame($admin->id, $version->published_by);
    }

    #[Test]
    public function bloquea_publicar_dos_veces(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $version = PlanVersion::factory()->create(['plan_id' => $plan->id]);

        (new PublishPlanVersion())($version, $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLAN_VERSION_ALREADY_PUBLISHED');
        (new PublishPlanVersion())($version->fresh(), $admin);
    }

    #[Test]
    public function bd_bloquea_revision_humana_requerida_con_limite_cero(): void
    {
        $plan = Plan::factory()->create();
        $this->expectException(QueryException::class);
        // Viola CHECK plan_versions_human_review_coherence.
        PlanVersion::factory()->create([
            'plan_id' => $plan->id,
            'human_review_required' => true,
            'human_review_limit' => 0,
        ]);
    }

    #[Test]
    public function rechaza_valores_invalidos_a_nivel_bd(): void
    {
        $plan = Plan::factory()->create();
        $this->expectException(QueryException::class);
        DB::table('plan_versions')->insert([
            'plan_id' => $plan->id,
            'number' => 1,
            'price_minor' => 0,
            'currency' => 'MXN',
            'interval_unit' => 'month',
            'interval_count' => 1,
            'max_planning_days' => 0, // viola CHECK
            'planning_limit' => 4,
            'human_review_limit' => 0,
            'correction_limit' => 0,
            'group_limit' => 1,
            'correction_window_days' => 0,
            'sla_hours' => 0,
            'human_review_required' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    #[Test]
    public function unicidad_plan_id_number(): void
    {
        $plan = Plan::factory()->create();
        PlanVersion::factory()->create(['plan_id' => $plan->id, 'number' => 1]);

        $this->expectException(QueryException::class);
        PlanVersion::factory()->create(['plan_id' => $plan->id, 'number' => 1]);
    }

    #[Test]
    public function publicada_inmutable_via_eloquent(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $version = PlanVersion::factory()->create(['plan_id' => $plan->id]);
        (new PublishPlanVersion())($version, $admin);

        $version = $version->fresh();
        $version->price_minor = 999;

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLAN_VERSION_PUBLISHED_IMMUTABLE');
        $version->save();
    }

    #[Test]
    public function publicada_inmutable_via_trigger_bd(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $version = PlanVersion::factory()->create(['plan_id' => $plan->id]);
        (new PublishPlanVersion())($version, $admin);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('PLAN_VERSION_PUBLISHED');
        DB::table('plan_versions')->where('id', $version->id)->update(['price_minor' => 42]);
    }

    #[Test]
    public function nueva_version_coexiste_con_publicada(): void
    {
        $admin = $this->admin();
        $plan = Plan::factory()->create();
        $v1 = PlanVersion::factory()->create(['plan_id' => $plan->id, 'number' => 1]);
        (new PublishPlanVersion())($v1, $admin);

        $v2 = PlanVersion::factory()->create(['plan_id' => $plan->id, 'number' => 2, 'price_minor' => 22000]);
        $this->assertTrue($v2->isDraft());
        $this->assertTrue($v1->fresh()->isPublished());
    }

    #[Test]
    public function customer_y_reviewer_no_pueden_crear_ni_publicar(): void
    {
        $plan = Plan::factory()->create();
        $version = PlanVersion::factory()->create(['plan_id' => $plan->id]);

        $this->assertFalse($this->customer()->can('create', PlanVersion::class));
        $this->assertFalse($this->reviewer()->can('publish', $version));
        $this->assertTrue($this->admin()->can('publish', $version));
    }
}
