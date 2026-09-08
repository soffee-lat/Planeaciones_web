<?php

namespace Tests\Feature;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Enums\RoleCode;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationalPhase;
use App\Models\FormativeField;
use App\Models\Grade;
use App\Models\Pda;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\TestCase;

class CurriculumCatalogTest extends TestCase
{
    use RefreshDatabase;

    // ---------- Helpers ----------

    private function admin(): User
    {
        return User::factory()->withRole(RoleCode::Administrator)->create();
    }

    /** Build a fully valid draft tree ready to publish. Returns the version. */
    private function buildValidDraft(?Curriculum $curriculum = null, int $number = 1): CurriculumVersion
    {
        $curriculum ??= Curriculum::factory()->create();
        $version = CurriculumVersion::factory()->create([
            'curriculum_id' => $curriculum->id,
            'number' => $number,
        ]);
        $phase = EducationalPhase::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-' . $number . '-A',
        ]);
        $grade = Grade::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phase->id,
            'code' => 'GR-' . $number . '-A1',
        ]);
        $field = FormativeField::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'FF-' . $number . '-LANG',
        ]);
        $content = CurricularContent::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phase->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-' . $number . '-1',
        ]);
        Pda::factory()->create([
            'curriculum_version_id' => $version->id,
            'curricular_content_id' => $content->id,
            'grade_id' => $grade->id,
            'code' => 'PDA-' . $number . '-1',
        ]);
        ArticulatingAxis::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'AX-' . $number . '-1',
        ]);

        return $version->refresh();
    }

    // ---------- Publicación válida ----------

    public function test_publicacion_valida_marca_metadata_y_calcula_checksum(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();

        $published = app(PublishCurriculumVersion::class)($version, $admin);

        $this->assertNotNull($published->published_at);
        $this->assertSame($admin->id, $published->published_by);
        $this->assertNotEmpty($published->checksum);
        $this->assertSame(64, strlen($published->checksum));
    }

    // ---------- Publicación inválida ----------

    public function test_publicacion_invalida_por_arbol_vacio(): void
    {
        $admin = $this->admin();
        $version = CurriculumVersion::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CURRICULUM_VERSION_EMPTY_PHASES');
        app(PublishCurriculumVersion::class)($version, $admin);
    }

    public function test_publicacion_invalida_por_contenido_sin_pda(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();

        // Add a second content without PDA.
        $content = CurricularContent::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $version->phases()->first()->id,
            'formative_field_id' => $version->formativeFields()->first()->id,
            'code' => 'CT-ORPHAN',
        ]);

        try {
            app(PublishCurriculumVersion::class)($version, $admin);
            $this->fail('Expected content-without-PDA error.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('CURRICULUM_CONTENT_WITHOUT_PDA:', $e->getMessage());
            $this->assertStringContainsString('CT-ORPHAN', $e->getMessage());
        }
    }

    public function test_publicacion_invalida_por_pda_de_grado_incorrecto(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();

        // Create a second phase + grade and attach PDA of that grade to the existing content (phase mismatch).
        $otherPhase = EducationalPhase::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-OTHER',
        ]);
        $otherGrade = Grade::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $otherPhase->id,
            'code' => 'GR-OTHER',
        ]);
        $content = $version->curricularContents()->first();
        Pda::factory()->create([
            'curriculum_version_id' => $version->id,
            'curricular_content_id' => $content->id,
            'grade_id' => $otherGrade->id,
            'code' => 'PDA-MISMATCH',
        ]);

        try {
            app(PublishCurriculumVersion::class)($version, $admin);
            $this->fail('Expected phase mismatch error.');
        } catch (RuntimeException $e) {
            $this->assertStringStartsWith('PDA_GRADE_PHASE_MISMATCH:', $e->getMessage());
        }
    }

    // ---------- Inmutabilidad tras publicar ----------

    public function test_inmutabilidad_bloquea_updates_en_hijos_de_version_publicada(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();
        app(PublishCurriculumVersion::class)($version, $admin);

        $phase = $version->phases()->first();
        $this->expectExceptionMessageMatches('/CURRICULUM_VERSION_PUBLISHED/');
        DB::table('educational_phases')->where('id', $phase->id)->update(['name' => 'nuevo nombre']);
    }

    public function test_inmutabilidad_bloquea_deletes_en_hijos_de_version_publicada(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();
        app(PublishCurriculumVersion::class)($version, $admin);

        $pda = $version->pdas()->first();
        $this->expectExceptionMessageMatches('/CURRICULUM_VERSION_PUBLISHED/');
        DB::table('pdas')->where('id', $pda->id)->delete();
    }

    public function test_inmutabilidad_bloquea_inserts_en_hijos_de_version_publicada(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();
        app(PublishCurriculumVersion::class)($version, $admin);

        $this->expectExceptionMessageMatches('/CURRICULUM_VERSION_PUBLISHED/');
        EducationalPhase::create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-NEW',
            'name' => 'nueva',
            'sort_order' => 99,
        ]);
    }

    public function test_inmutabilidad_bloquea_modificar_la_version_publicada(): void
    {
        $admin = $this->admin();
        $version = $this->buildValidDraft();
        app(PublishCurriculumVersion::class)($version, $admin);

        $this->expectExceptionMessageMatches('/CURRICULUM_VERSION_PUBLISHED/');
        DB::table('curriculum_versions')->where('id', $version->id)->update(['label' => 'otro']);
    }

    // ---------- Mezcla de versiones ----------

    public function test_mezcla_de_versiones_es_rechazada_por_fk_compuesta(): void
    {
        $curriculum = Curriculum::factory()->create();
        $v1 = CurriculumVersion::factory()->create(['curriculum_id' => $curriculum->id, 'number' => 1]);
        $v2 = CurriculumVersion::factory()->create(['curriculum_id' => $curriculum->id, 'number' => 2]);

        $phaseV1 = EducationalPhase::factory()->create([
            'curriculum_version_id' => $v1->id,
            'code' => 'PH-V1',
        ]);

        // Attempt to create a Grade in v2 referencing a phase from v1 → composite FK violation.
        $this->expectException(\Illuminate\Database\QueryException::class);
        Grade::create([
            'curriculum_version_id' => $v2->id,
            'educational_phase_id' => $phaseV1->id,
            'code' => 'GR-CROSS',
            'name' => 'cross',
            'ordinal' => 1,
            'sort_order' => 1,
        ]);
    }

    // ---------- Códigos duplicados ----------

    public function test_codigos_duplicados_por_version_son_rechazados(): void
    {
        $version = CurriculumVersion::factory()->create();
        EducationalPhase::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-DUP',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        EducationalPhase::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-DUP',
        ]);
    }

    // ---------- selectable_version_id ----------

    public function test_selectable_version_id_acepta_version_publicada_del_mismo_curriculo(): void
    {
        $admin = $this->admin();
        $curriculum = Curriculum::factory()->create();
        $v1 = $this->buildValidDraft($curriculum, 1);
        app(PublishCurriculumVersion::class)($v1, $admin);

        $curriculum->fill(['selectable_version_id' => $v1->id])->save();
        $this->assertSame($v1->id, $curriculum->fresh()->selectable_version_id);
    }

    public function test_selectable_version_id_rechaza_borrador(): void
    {
        $curriculum = Curriculum::factory()->create();
        $draft = CurriculumVersion::factory()->create(['curriculum_id' => $curriculum->id, 'number' => 1]);

        $this->expectExceptionMessageMatches('/SELECTABLE_VERSION_NOT_PUBLISHED/');
        $curriculum->fill(['selectable_version_id' => $draft->id])->save();
    }

    public function test_selectable_version_id_rechaza_version_de_otro_curriculo(): void
    {
        $admin = $this->admin();
        $curriculumA = Curriculum::factory()->create();
        $curriculumB = Curriculum::factory()->create();
        $vB = $this->buildValidDraft($curriculumB, 1);
        app(PublishCurriculumVersion::class)($vB, $admin);

        $this->expectExceptionMessageMatches('/SELECTABLE_VERSION_MISMATCHED_CURRICULUM/');
        $curriculumA->fill(['selectable_version_id' => $vB->id])->save();
    }

    // ---------- Accesos administrativos ----------

    public function test_administrador_puede_acceder_al_listado_de_curricula(): void
    {
        $admin = $this->admin();
        $this->actingAs($admin)->get('/admin/curricula')->assertOk();
        $this->actingAs($admin)->get('/admin/curriculum-versions')->assertOk();
    }

    public function test_cliente_y_revisor_no_pueden_acceder_a_admin_curricula(): void
    {
        $customer = User::factory()->withRole(RoleCode::Customer)->create();
        $reviewer = User::factory()->withRole(RoleCode::Reviewer)->create();

        $this->actingAs($customer)->get('/admin/curricula')->assertForbidden();
        $this->actingAs($reviewer)->get('/admin/curricula')->assertForbidden();
    }
}
