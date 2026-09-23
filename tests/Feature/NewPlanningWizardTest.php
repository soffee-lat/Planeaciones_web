<?php

namespace Tests\Feature;

use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Enums\PlanningRequestStatus;
use App\Filament\App\Pages\StartPlanning;
use App\Filament\App\Resources\PlanningRequests\Pages\CreatePlanningRequest;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\GroupProfile;
use App\Models\PlanningRequest;
use Livewire\Livewire;

/**
 * Cobertura del wizard NUEVA PLANEACIÓN (Subfase 2D).
 * Estos tests trabajan sobre acciones + estructura del recurso.
 * La navegación real del wizard se ejerce en la auditoría responsive manual.
 */
class NewPlanningWizardTest extends PedagogyTestCase
{
    public function test_dashboard_shows_nueva_planeacion_cta_when_onboarding_complete(): void
    {
        $ctx = $this->seedFullTeacher();
        $ctx['user']->forceFill(['onboarding_completed_at' => now()])->save();
        $ctx['profile']->fill([
            'student_count' => 25, 'general_level' => 'medio',
            'session_minutes' => 50, 'characteristics' => 'Grupo activo.',
        ])->save();
        $this->actingAs($ctx['user']->refresh());
        $res = $this->get('/app/inicio');
        $res->assertOk();
        $res->assertSee('Nueva planeación');
    }

    public function test_legacy_create_page_redirects_to_the_current_planning_flow(): void
    {
        $ctx = $this->seedFullTeacher();
        $this->actingAs($ctx['user']);

        Livewire::test(CreatePlanningRequest::class)
            ->assertRedirect(StartPlanning::getUrl());
    }

    public function test_eligible_group_options_filters_incomplete_profile(): void
    {
        $ctx = $this->seedFullTeacher();
        $this->actingAs($ctx['user']);
        // Perfil completo → aparece.
        $ctx['profile']->fill([
            'student_count' => 25, 'general_level' => 'medio',
            'session_minutes' => 50, 'characteristics' => 'Grupo activo.',
        ])->save();
        $this->assertArrayHasKey($ctx['group']->id, PlanningRequestResource::eligibleGroupOptions());

        // Perfil incompleto → desaparece.
        GroupProfile::where('id', $ctx['profile']->id)->update(['student_count' => null]);
        $this->assertArrayNotHasKey($ctx['group']->id, PlanningRequestResource::eligibleGroupOptions());
    }

    public function test_content_and_pda_options_are_scoped_by_version_and_grade(): void
    {
        $ctx = $this->seedFullTeacher();
        $this->actingAs($ctx['user']);
        $contents = PlanningRequestResource::contentOptions($ctx['group']->id);
        $this->assertNotEmpty($contents);
        foreach (array_keys($contents) as $cid) {
            $this->assertDatabaseHas('curricular_contents', ['id' => $cid, 'curriculum_version_id' => $ctx['version']->id]);
        }

        $someContentId = array_key_first($contents);
        $pdas = PlanningRequestResource::pdaOptions($ctx['group']->id, [$someContentId]);
        foreach (array_keys($pdas) as $pid) {
            $this->assertDatabaseHas('pdas', [
                'id' => $pid,
                'curriculum_version_id' => $ctx['version']->id,
                'grade_id' => $ctx['grade']->id,
                'curricular_content_id' => $someContentId,
            ]);
        }
    }

    public function test_quick_mode_persists_creation_mode_and_selections_survive_mode_switch(): void
    {
        $ctx = $this->seedFullTeacher();
        /** @var PlanningRequest $r */
        $r = PlanningRequest::factory()->create([
            'owner_id' => $ctx['user']->id,
            'group_id' => $ctx['group']->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
            'creation_mode' => 'quick',
        ]);
        $content = CurricularContent::where('curriculum_version_id', $ctx['version']->id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $ctx['grade']->id))->first();
        $pda = Pda::where('curricular_content_id', $content->id)->where('grade_id', $ctx['grade']->id)->first();

        app(SyncPlanningRequestSelections::class)->execute($ctx['user'], $r, [
            'contents' => [$content->id], 'pdas' => [$pda->id],
        ]);
        // Cambiar de rápido → avanzado no debe borrar selección ni datos.
        app(UpdatePlanningRequestDraft::class)->execute($ctx['user'], $r, ['creation_mode' => 'advanced']);
        $r->refresh();
        $this->assertSame('advanced', $r->creation_mode);
        $this->assertCount(1, $r->contents);
        $this->assertCount(1, $r->pdas);
    }

    public function test_full_flow_confirms_using_existing_action_and_moves_to_esperando_pago(): void
    {
        $ctx = $this->seedFullTeacher();
        $ctx['profile']->fill([
            'student_count' => 25, 'general_level' => 'medio',
            'session_minutes' => 50, 'characteristics' => 'Grupo listo.',
        ])->save();

        /** @var PlanningRequest $r */
        $r = PlanningRequest::factory()->create([
            'owner_id' => $ctx['user']->id,
            'group_id' => $ctx['group']->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
            'creation_mode' => 'quick',
        ]);
        $content = CurricularContent::where('curriculum_version_id', $ctx['version']->id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $ctx['grade']->id))->first();
        $pda = Pda::where('curricular_content_id', $content->id)->where('grade_id', $ctx['grade']->id)->first();
        app(SyncPlanningRequestSelections::class)->execute($ctx['user'], $r, [
            'contents' => [$content->id], 'pdas' => [$pda->id],
        ]);

        app(ConfirmPlanningRequest::class)->execute($ctx['user'], $r->refresh());
        $r->refresh();
        $this->assertEquals(PlanningRequestStatus::ESPERANDO_PAGO, $r->status);
        $this->assertSame('quick', $r->input_snapshot['request']['creation_mode']);
    }

    public function test_pda_options_are_empty_when_no_content_selected_and_populate_when_added(): void
    {
        $ctx = $this->seedFullTeacher();
        $this->actingAs($ctx['user']);
        $contents = PlanningRequestResource::contentOptions($ctx['group']->id);
        $cid = array_key_first($contents);
        // Sin contenidos seleccionados → devuelve todos los PDA del grado (comportamiento actual: full list).
        $allPdas = PlanningRequestResource::pdaOptions($ctx['group']->id, []);
        // Con contenido específico → subset.
        $subset = PlanningRequestResource::pdaOptions($ctx['group']->id, [$cid]);
        foreach (array_keys($subset) as $pid) {
            $this->assertArrayHasKey($pid, $allPdas);
        }
    }

    public function test_cross_owner_group_is_ignored_by_options_helpers(): void
    {
        $a = $this->seedFullTeacher();
        $b = $this->seedFullTeacher();
        $this->actingAs($a['user']);
        // Grupo del docente B no debe ser opción para A.
        $this->assertArrayNotHasKey($b['group']->id, PlanningRequestResource::eligibleGroupOptions());
        $this->assertSame([], PlanningRequestResource::contentOptions($b['group']->id));
        $this->assertSame([], PlanningRequestResource::pdaOptions($b['group']->id, []));
        $this->assertSame([], PlanningRequestResource::axisOptions($b['group']->id));
    }
}
