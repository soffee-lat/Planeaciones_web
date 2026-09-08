<?php

namespace Tests\Feature;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Actions\Planning\GenerateCurriculumSuggestions;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationalPhase;
use App\Models\FormativeField;
use App\Models\Grade;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\Planning\CurriculumSuggestionService;

class CurriculumSuggestionServiceTest extends PedagogyTestCase
{
    /**
     * Publica un currículo con dos contenidos + varios PDAs para poder distinguir
     * coincidencia por título vs full_text.
     *
     * @return array{version:CurriculumVersion,grade:Grade,otherGrade:Grade,cA:CurricularContent,cB:CurricularContent,pdaA1:Pda,pdaA2:Pda,pdaB1:Pda,axisReading:ArticulatingAxis,axisMath:ArticulatingAxis}
     */
    private function seedRichCurriculum(): array
    {
        $admin = $this->admin();
        $curr = Curriculum::factory()->create();
        $ver = CurriculumVersion::factory()->create(['curriculum_id' => $curr->id, 'number' => 1]);
        $phaseA = EducationalPhase::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'PH-Q']);
        $phaseB = EducationalPhase::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'PH-R']);
        $gA = Grade::factory()->create(['curriculum_version_id' => $ver->id, 'educational_phase_id' => $phaseA->id, 'code' => 'GR-A', 'ordinal' => 1]);
        $gB = Grade::factory()->create(['curriculum_version_id' => $ver->id, 'educational_phase_id' => $phaseB->id, 'code' => 'GR-B', 'ordinal' => 2]);
        $field = FormativeField::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'FF-Q']);

        $cA = CurricularContent::factory()->create([
            'curriculum_version_id' => $ver->id,
            'educational_phase_id' => $phaseA->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-A',
            'title' => 'Ecosistemas y biodiversidad local',
            'full_text' => 'Estudio de los ecosistemas mexicanos, seres vivos y sus relaciones con el entorno.',
        ]);
        $cB = CurricularContent::factory()->create([
            'curriculum_version_id' => $ver->id,
            'educational_phase_id' => $phaseA->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-B',
            'title' => 'Historia de las civilizaciones antiguas',
            'full_text' => 'Culturas mesoamericanas, egipcia y romana; línea del tiempo y contexto.',
        ]);

        $pdaA1 = Pda::factory()->create(['curriculum_version_id' => $ver->id, 'curricular_content_id' => $cA->id, 'grade_id' => $gA->id, 'code' => 'PDA-A1', 'full_text' => 'Identifica ecosistemas de su localidad.']);
        $pdaA2 = Pda::factory()->create(['curriculum_version_id' => $ver->id, 'curricular_content_id' => $cA->id, 'grade_id' => $gA->id, 'code' => 'PDA-A2', 'full_text' => 'Explica cadenas alimenticias entre seres vivos.']);
        $pdaB1 = Pda::factory()->create(['curriculum_version_id' => $ver->id, 'curricular_content_id' => $cB->id, 'grade_id' => $gA->id, 'code' => 'PDA-B1', 'full_text' => 'Ubica en línea del tiempo civilizaciones antiguas.']);
        // Contenido y PDA en la fase B (grado gB) — la PDA no debe aparecer al filtrar por gA.
        $cC = CurricularContent::factory()->create([
            'curriculum_version_id' => $ver->id,
            'educational_phase_id' => $phaseB->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-C',
            'title' => 'Ecosistemas y biodiversidad avanzada',
            'full_text' => 'Estudio ampliado de ecosistemas y seres vivos.',
        ]);
        Pda::factory()->create(['curriculum_version_id' => $ver->id, 'curricular_content_id' => $cC->id, 'grade_id' => $gB->id, 'code' => 'PDA-C1-OTHER', 'full_text' => 'PDA de otro grado con ecosistemas también.']);

        $axisReading = ArticulatingAxis::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'AX-LECTURA', 'name' => 'Vida saludable y ecosistemas', 'description' => 'Cuidado del entorno.']);
        $axisMath = ArticulatingAxis::factory()->create(['curriculum_version_id' => $ver->id, 'code' => 'AX-INTERC', 'name' => 'Interculturalidad', 'description' => 'Diversidad cultural.']);

        app(PublishCurriculumVersion::class)($ver, $admin);
        $curr->fill(['selectable_version_id' => $ver->id])->save();

        return compact('ver', 'gA', 'gB', 'cA', 'cB', 'pdaA1', 'pdaA2', 'pdaB1', 'axisReading', 'axisMath') + [
            'version' => $ver, 'grade' => $gA, 'otherGrade' => $gB,
        ];
    }

    public function test_returns_only_catalog_items_and_filters_by_version_and_grade(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Proyecto sobre ecosistemas']);

        // Todos los IDs pertenecen al catálogo publicado.
        $this->assertNotEmpty($res['content_ids']);
        foreach ($res['content_ids'] as $cid) {
            $this->assertDatabaseHas('curricular_contents', ['id' => $cid, 'curriculum_version_id' => $s['version']->id]);
        }
        foreach ($res['pda_ids'] as $pid) {
            $this->assertDatabaseHas('pdas', ['id' => $pid, 'curriculum_version_id' => $s['version']->id, 'grade_id' => $s['grade']->id]);
        }
        foreach ($res['axis_ids'] as $aid) {
            $this->assertDatabaseHas('articulating_axes', ['id' => $aid, 'curriculum_version_id' => $s['version']->id]);
        }
    }

    public function test_prioritises_title_match_over_pda_only_match(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        // "civilizaciones" está en el título de cB → debe salir antes que cA.
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Estudiaremos civilizaciones antiguas']);
        $this->assertNotEmpty($res['content_ids']);
        $this->assertSame($s['cB']->id, $res['content_ids'][0]);
        $this->assertTrue($res['has_strong_match']);
    }

    public function test_matches_full_text_when_title_has_no_match(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        // "mesoamericanas" sólo está en cB.full_text (título no la incluye).
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Culturas mesoamericanas del centro']);
        $this->assertContains($s['cB']->id, $res['content_ids']);
    }

    public function test_never_returns_pda_from_another_grade(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Estudio de ecosistemas locales']);
        $foreignPda = Pda::where('curriculum_version_id', $s['version']->id)
            ->where('grade_id', $s['otherGrade']->id)->pluck('id')->all();
        foreach ($res['pda_ids'] as $pid) {
            $this->assertNotContains($pid, $foreignPda);
        }
    }

    public function test_never_returns_items_from_another_version(): void
    {
        $s = $this->seedRichCurriculum();
        // Segunda publicación totalmente aislada.
        $other = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Ecosistemas mexicanos']);
        foreach ($res['content_ids'] as $cid) {
            $this->assertDatabaseMissing('curricular_contents', ['id' => $cid, 'curriculum_version_id' => $other['version']->id]);
        }
    }

    public function test_returns_empty_result_when_no_matches(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'zzzz palabra inexistente qqqq']);
        $this->assertSame([], $res['content_ids']);
        $this->assertSame([], $res['pda_ids']);
        $this->assertFalse($res['has_strong_match']);
    }

    public function test_result_is_deterministic_for_same_input(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $a = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Ecosistemas y civilizaciones antiguas']);
        $b = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Ecosistemas y civilizaciones antiguas']);
        $this->assertSame($a['content_ids'], $b['content_ids']);
        $this->assertSame($a['pda_ids'], $b['pda_ids']);
        $this->assertSame($a['axis_ids'], $b['axis_ids']);
    }

    public function test_axes_suggested_only_when_name_or_description_matches(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'Vida saludable y ecosistemas']);
        $this->assertContains($s['axisReading']->id, $res['axis_ids']);
        $this->assertNotContains($s['axisMath']->id, $res['axis_ids']);
    }

    public function test_action_authorises_owner_only_and_rejects_confirmed(): void
    {
        $scene = $this->seedFullTeacher();
        /** @var PlanningRequest $r */
        $r = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'project' => 'Ecosistemas',
        ]);
        $stranger = $this->customer();
        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(GenerateCurriculumSuggestions::class)->execute($stranger, $r);
    }

    public function test_service_ignores_short_tokens_and_stopwords(): void
    {
        $s = $this->seedRichCurriculum();
        $svc = app(CurriculumSuggestionService::class);
        $res = $svc->suggest($s['version']->id, $s['grade']->id, ['project' => 'y la de en el a']);
        $this->assertSame([], $res['content_ids']);
        $this->assertSame([], $res['pda_ids']);
    }
}
