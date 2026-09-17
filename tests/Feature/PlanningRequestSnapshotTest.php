<?php

namespace Tests\Feature;

use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Enums\PlanningRequestStatus;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Models\RequestInputVersion;
use App\Models\RequestStateEvent;
use Illuminate\Support\Facades\DB;

class PlanningRequestSnapshotTest extends PedagogyTestCase
{
    /** @return array{req:PlanningRequest, scene:array} */
    private function makeReadyDraft(): array
    {
        $scene = $this->seedFullTeacher();
        /** @var PlanningRequest $req */
        $req = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
        ]);
        // Ensure profile isSufficient
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo participativo.',
        ])->save();

        $content = CurricularContent::query()
            ->where('curriculum_version_id', $scene['version']->id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $scene['grade']->id))
            ->firstOrFail();
        $pda = Pda::query()->where('curricular_content_id', $content->id)
            ->where('grade_id', $scene['grade']->id)->firstOrFail();
        app(SyncPlanningRequestSelections::class)->execute($req->owner, $req, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);
        return ['req' => $req->refresh(), 'scene' => $scene];
    }

    public function test_confirm_creates_snapshot_with_texts_and_profile_revision_and_checksum(): void
    {
        ['req' => $req, 'scene' => $s] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);

        $this->assertEquals(PlanningRequestStatus::ESPERANDO_PAGO, $req->status);
        $this->assertNotNull($req->curriculum_confirmed_at);
        $this->assertGreaterThan(0, $req->input_revision);
        $this->assertNotNull($req->current_version_id);

        /** @var RequestInputVersion $riv */
        $riv = $req->currentInputVersion;
        $this->assertNotNull($riv);
        $snap = $riv->snapshot;

        // Contains textual content, not just IDs.
        $this->assertNotEmpty($snap['curriculum']['contents']);
        $this->assertArrayHasKey('full_text', $snap['curriculum']['contents'][0]);
        $this->assertNotEmpty($snap['curriculum']['contents'][0]['full_text']);
        $this->assertArrayHasKey('full_text', $snap['curriculum']['pdas'][0]);

        // GroupProfile.revision included.
        $this->assertArrayHasKey('revision', $snap['group']['profile']);
        $this->assertSame((int) $s['profile']->refresh()->revision, $snap['group']['profile']['revision']);

        // Curriculum checksum included and non-empty (comes from published version).
        $this->assertNotEmpty($snap['curriculum']['version']['checksum']);

        // State event recorded.
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $req->id,
            'from_status' => 'BORRADOR',
            'to_status' => 'ESPERANDO_PAGO',
        ]);
    }

    public function test_snapshot_is_frozen_and_immune_to_later_group_profile_changes(): void
    {
        ['req' => $req, 'scene' => $s] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);
        $originalSnap = $req->currentInputVersion->snapshot;

        // Cambiar el perfil pedagógico posteriormente.
        $s['profile']->fill(['student_count' => 99, 'general_level' => 'alto', 'characteristics' => 'Cambio posterior'])->save();
        $s['profile']->refresh();

        // El snapshot NO debe cambiar.
        $freshRiv = RequestInputVersion::query()->find($req->currentInputVersion->id);
        $this->assertSame($originalSnap, $freshRiv->snapshot);
        $this->assertSame(25, $freshRiv->snapshot['group']['profile']['student_count']);
    }

    public function test_snapshot_is_immune_to_selectable_version_change(): void
    {
        ['req' => $req, 'scene' => $s] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);
        $frozenVersionId = $req->currentInputVersion->snapshot['curriculum']['version']['id'];

        // Cambiar selectable_version_id del currículo a NULL (simulando un cambio comercial).
        $s['curriculum']->fill(['selectable_version_id' => null])->save();

        // Snapshot preservado.
        $riv = RequestInputVersion::query()->find($req->currentInputVersion->id);
        $this->assertSame($frozenVersionId, $riv->snapshot['curriculum']['version']['id']);
    }

    public function test_confirmed_snapshot_cannot_be_updated_or_deleted(): void
    {
        ['req' => $req] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);
        $riv = $req->currentInputVersion;

        $this->expectException(\RuntimeException::class);
        $riv->reason = 'tampered';
        $riv->save();
    }

    public function test_confirmed_snapshot_cannot_be_updated_at_db_level(): void
    {
        ['req' => $req] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);
        $riv = $req->currentInputVersion;

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('request_input_versions')->where('id', $riv->id)->update(['reason' => 'db_tamper']);
    }

    public function test_confirm_rejects_when_profile_insufficient(): void
    {
        ['req' => $req, 'scene' => $s] = $this->makeReadyDraft();
        // Vaciar campo obligatorio (student_count).
        DB::table('group_profiles')->where('id', $s['profile']->id)->update(['student_count' => null]);
        $this->expectExceptionMessage('PLANNING_REQUEST_GROUP_PROFILE_INSUFFICIENT');
        app(ConfirmPlanningRequest::class)->execute($req->owner, $req);
    }

    public function test_confirm_rejects_when_content_has_no_selected_pda(): void
    {
        // Escenario aislado: currículo productivamente elegible con 2 contenidos
        // en el mismo grado, pero sólo 1 PDA seleccionado, para forzar
        // específicamente el path CONTENT_WITHOUT_PDA.
        $admin = $this->admin();
        $curr = \App\Models\Curriculum::factory()->create([
            'code' => 'SEP-TEST-Z',
            'name' => 'Currículo SEP de validación Z',
            'country_code' => 'MX',
            'educational_level' => 'primary',
            'description' => 'Catálogo aislado para validar invariantes de selección curricular.',
        ]);
        $ver = \App\Models\CurriculumVersion::factory()->create([
            'curriculum_id' => $curr->id,
            'number' => 1,
            'label' => 'Edición 2026 Z',
            'source_reference' => 'fixture://planning-request-snapshot/z',
        ]);
        $phase = \App\Models\EducationalPhase::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'PH-Z']);
        $grade = \App\Models\Grade::factory()->create(['curriculum_version_id' => $ver->id, 'educational_phase_id' => $phase->id, 'code' => 'GR-Z', 'ordinal' => 1]);
        $field = \App\Models\FormativeField::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'FF-Z']);
        $c1 = CurricularContent::factory()->create(['curriculum_version_id' => $ver->id, 'educational_phase_id' => $phase->id, 'formative_field_id' => $field->id, 'code' => 'CT-Z1']);
        $c2 = CurricularContent::factory()->create(['curriculum_version_id' => $ver->id, 'educational_phase_id' => $phase->id, 'formative_field_id' => $field->id, 'code' => 'CT-Z2']);
        $pdaC1 = Pda::factory()->create(['curriculum_version_id' => $ver->id, 'curricular_content_id' => $c1->id, 'grade_id' => $grade->id, 'code' => 'PDA-Z1']);
        Pda::factory()->create(['curriculum_version_id' => $ver->id, 'curricular_content_id' => $c2->id, 'grade_id' => $grade->id, 'code' => 'PDA-Z2']);
        \App\Models\ArticulatingAxis::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'AX-Z']);
        app(\App\Actions\Curriculum\PublishCurriculumVersion::class)($ver, $admin);
        $curr->fill(['selectable_version_id' => $ver->id])->save();

        $user = $this->customer();
        $school = \App\Models\School::factory()->create(['owner_id' => $user->id, 'school_type' => \App\Enums\SchoolType::Public->value]);
        $group = \App\Models\Group::create(['owner_id' => $user->id, 'school_id' => $school->id, 'curriculum_version_id' => $ver->id, 'grade_id' => $grade->id, 'name' => 'GZ', 'school_year' => '2026-2027']);
        \App\Models\GroupProfile::factory()->create([
            'group_id' => $group->id,
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo Z.',
        ]);
        $req = PlanningRequest::factory()->create([
            'owner_id' => $user->id,
            'group_id' => $group->id,
            'curriculum_version_id' => $ver->id,
            'grade_id' => $grade->id,
        ]);
        // Seleccionamos los dos contenidos pero sólo el PDA del primero.
        app(\App\Actions\Planning\SyncPlanningRequestSelections::class)->execute($user, $req, [
            'contents' => [$c1->id, $c2->id],
            'pdas' => [$pdaC1->id],
        ]);

        $this->expectExceptionMessageMatches('/PLANNING_REQUEST_CONTENT_WITHOUT_PDA/');
        app(ConfirmPlanningRequest::class)->execute($user, $req->refresh());
    }

    public function test_confirm_rejects_when_already_confirmed(): void
    {
        ['req' => $req] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);
        // Segundo intento: cualquier excepción es aceptable (policy o dominio).
        $threw = false;
        try {
            app(ConfirmPlanningRequest::class)->execute($req->owner, $req->refresh());
        } catch (\Throwable $e) {
            $threw = true;
        }
        $this->assertTrue($threw, 'Segundo confirm debe fallar.');
        // Y sigue como ESPERANDO_PAGO.
        $this->assertEquals(PlanningRequestStatus::ESPERANDO_PAGO, $req->refresh()->status);
    }

    public function test_historical_request_survives_selectable_version_change(): void
    {
        ['req' => $req, 'scene' => $s] = $this->makeReadyDraft();
        $req = app(ConfirmPlanningRequest::class)->execute($req->owner, $req);

        // Simular cambio comercial de currículo: se retira la versión seleccionable.
        $s['curriculum']->fill(['selectable_version_id' => null])->save();

        // La solicitud confirmada sigue consultable con sus IDs originales.
        $reloaded = PlanningRequest::query()->find($req->id);
        $this->assertEquals(PlanningRequestStatus::ESPERANDO_PAGO, $reloaded->status);
        $this->assertEquals($s['version']->id, $reloaded->curriculum_version_id);
        $this->assertNotNull($reloaded->input_snapshot);
    }
}
