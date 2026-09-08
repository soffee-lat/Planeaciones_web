<?php

namespace Tests\Feature;

use App\Enums\SchoolType;
use App\Models\Group;
use App\Models\School;
use Illuminate\Database\QueryException;

class GroupTest extends PedagogyTestCase
{
    public function test_grupo_valido_se_crea_con_composite_fk_correcta(): void
    {
        $user = $this->customer();
        $ctx = $this->seedPublishedCurriculum();
        $school = School::factory()->create(['owner_id' => $user->id, 'school_type' => SchoolType::Public->value]);

        $group = Group::create([
            'owner_id' => $user->id,
            'school_id' => $school->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
            'name' => '1° A',
            'school_year' => '2026-2027',
        ]);

        $this->assertNotNull($group->id);
        $this->assertNull($group->archived_at);
    }

    public function test_escuela_de_otro_owner_es_rechazada_por_composite_fk(): void
    {
        $userA = $this->customer();
        $userB = $this->customer();
        $ctx = $this->seedPublishedCurriculum();
        $schoolB = School::factory()->create(['owner_id' => $userB->id, 'school_type' => SchoolType::Public->value]);

        $this->expectException(QueryException::class);
        Group::create([
            'owner_id' => $userA->id, // A intenta usar escuela de B
            'school_id' => $schoolB->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
            'name' => 'X',
            'school_year' => '2026-2027',
        ]);
    }

    public function test_version_borrador_es_rechazada_por_trigger(): void
    {
        $user = $this->customer();
        $ctx = $this->seedPublishedCurriculum();
        $school = School::factory()->create(['owner_id' => $user->id, 'school_type' => 'public']);

        $this->expectExceptionMessageMatches('/GROUP_CURRICULUM_VERSION_NOT_PUBLISHED/');
        Group::create([
            'owner_id' => $user->id,
            'school_id' => $school->id,
            'curriculum_version_id' => $ctx['draftVersion']->id,
            'grade_id' => $ctx['draftGrade']->id,
            'name' => 'X',
            'school_year' => '2026-2027',
        ]);
    }

    public function test_grade_de_otra_version_es_rechazado_por_composite_fk(): void
    {
        $user = $this->customer();
        $ctx = $this->seedPublishedCurriculum();
        $school = School::factory()->create(['owner_id' => $user->id, 'school_type' => 'public']);

        $this->expectException(QueryException::class);
        Group::create([
            'owner_id' => $user->id,
            'school_id' => $school->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['draftGrade']->id, // pertenece a la versión borrador
            'name' => 'X',
            'school_year' => '2026-2027',
        ]);
    }

    public function test_version_publicada_pero_no_seleccionable_es_rechazada(): void
    {
        // Publicamos una versión válida en OTRO currículo pero NO marcamos selectable_version_id.
        $user = $this->customer();
        $ctx = $this->seedPublishedCurriculum();
        $admin = $this->admin();

        $otherCurriculum = \App\Models\Curriculum::factory()->create();
        $v = \App\Models\CurriculumVersion::factory()->create(['curriculum_id' => $otherCurriculum->id, 'number' => 1]);
        $phase = \App\Models\EducationalPhase::factory()->create(['curriculum_version_id' => $v->id, 'code' => 'PH-Z']);
        $grade = \App\Models\Grade::factory()->create(['curriculum_version_id' => $v->id, 'educational_phase_id' => $phase->id, 'code' => 'GR-Z', 'ordinal' => 1]);
        $field = \App\Models\FormativeField::factory()->create(['curriculum_version_id' => $v->id, 'code' => 'FF-Z']);
        $content = \App\Models\CurricularContent::factory()->create([
            'curriculum_version_id' => $v->id,
            'educational_phase_id' => $phase->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-Z',
        ]);
        \App\Models\Pda::factory()->create(['curriculum_version_id' => $v->id, 'curricular_content_id' => $content->id, 'grade_id' => $grade->id, 'code' => 'PDA-Z']);
        \App\Models\ArticulatingAxis::factory()->create(['curriculum_version_id' => $v->id, 'code' => 'AX-Z']);

        app(\App\Actions\Curriculum\PublishCurriculumVersion::class)($v, $admin);
        // NO se marca selectable_version_id en $otherCurriculum.

        $school = School::factory()->create(['owner_id' => $user->id, 'school_type' => 'public']);

        $this->expectExceptionMessageMatches('/GROUP_CURRICULUM_VERSION_NOT_SELECTABLE/');
        Group::create([
            'owner_id' => $user->id,
            'school_id' => $school->id,
            'curriculum_version_id' => $v->id,
            'grade_id' => $grade->id,
            'name' => 'X',
            'school_year' => '2026-2027',
        ]);
    }

    public function test_grupo_archivado_no_aparece_en_flujo_activo(): void
    {
        $seed = $this->seedFullTeacher();
        $seed['group']->forceFill(['archived_at' => now()])->save();

        $this->assertFalse(
            \App\Models\Group::query()->active()->where('id', $seed['group']->id)->exists()
        );
        $this->assertTrue(
            \App\Models\Group::query()->archived()->where('id', $seed['group']->id)->exists()
        );
    }

    public function test_policy_bloquea_edicion_de_grupo_ajeno(): void
    {
        $a = $this->customer();
        $seedB = $this->seedFullTeacher();

        $this->assertFalse($a->can('view', $seedB['group']));
        $this->assertFalse($a->can('update', $seedB['group']));
    }

    public function test_admin_index_del_app_no_devuelve_grupos_de_otros_clientes(): void
    {
        $a = $this->customer();
        $seedB = $this->seedFullTeacher();

        // /app está pensado como panel del cliente. El cliente A no ve grupo de B en su listado.
        $response = $this->actingAs($a)->get('/app/groups');
        $response->assertOk();
        $response->assertDontSee($seedB['group']->name);
    }
}
