<?php

namespace Tests\Feature;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Enums\PlanningRequestStatus;
use App\Enums\SchoolType;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationalPhase;
use App\Models\FormativeField;
use App\Models\Grade;
use App\Models\Group;
use App\Models\GroupProfile;
use App\Models\Pda;
use App\Models\School;

class PreschoolPlanningFlowTest extends PedagogyTestCase
{
    public function test_third_grade_preschool_flow_keeps_level_grade_and_pedagogical_calibration(): void
    {
        $admin = $this->admin();
        $teacher = $this->customer();

        $curriculum = Curriculum::create([
            'code' => 'MX-NEM-PRESCHOOL-E2E',
            'name' => 'Preescolar — Fixture E2E',
            'country_code' => 'MX',
            'educational_level' => 'preschool',
            'description' => 'Datos sintéticos para probar el flujo, sin validez curricular.',
        ]);

        $version = CurriculumVersion::factory()->create([
            'curriculum_id' => $curriculum->id,
            'number' => 1,
            'label' => 'Fixture preescolar Fase 2',
            'source_reference' => 'fixture:test',
        ]);

        $phase = EducationalPhase::create([
            'curriculum_version_id' => $version->id,
            'code' => 'F2',
            'name' => 'Fase 2',
            'sort_order' => 2,
        ]);

        $grade = Grade::create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phase->id,
            'code' => 'P3',
            'name' => 'Tercer grado de preescolar',
            'ordinal' => 3,
            'sort_order' => 3,
        ]);

        $field = FormativeField::create([
            'curriculum_version_id' => $version->id,
            'code' => 'LEN',
            'name' => 'Lenguajes',
            'description' => 'Fixture sintético.',
            'sort_order' => 1,
        ]);

        $content = CurricularContent::create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phase->id,
            'formative_field_id' => $field->id,
            'code' => 'F2-LEN-TEST-C001',
            'title' => 'Contenido sintético de lenguaje',
            'full_text' => 'Contenido ficticio utilizado exclusivamente para validar el flujo de preescolar.',
            'source_locator' => 'fixture:test',
            'sort_order' => 1,
        ]);

        $pda = Pda::create([
            'curriculum_version_id' => $version->id,
            'curricular_content_id' => $content->id,
            'grade_id' => $grade->id,
            'code' => 'F2-P3-LEN-TEST-C001-P01',
            'full_text' => 'PDA ficticio utilizado exclusivamente para validar el flujo de preescolar.',
            'source_locator' => 'fixture:test',
            'sort_order' => 1,
        ]);

        ArticulatingAxis::create([
            'curriculum_version_id' => $version->id,
            'code' => 'AX-TEST',
            'name' => 'Eje sintético',
            'description' => 'Fixture sintético.',
            'sort_order' => 1,
        ]);

        app(PublishCurriculumVersion::class)($version, $admin);
        $curriculum->update(['selectable_version_id' => $version->id]);

        $school = School::factory()->create([
            'owner_id' => $teacher->id,
            'school_type' => SchoolType::Public->value,
        ]);

        $group = Group::create([
            'owner_id' => $teacher->id,
            'school_id' => $school->id,
            'curriculum_version_id' => $version->id,
            'grade_id' => $grade->id,
            'name' => '3° Preescolar A',
            'school_year' => '2026-2027',
        ]);

        GroupProfile::factory()->create([
            'group_id' => $group->id,
            'student_count' => 22,
            'general_level' => 'medio',
            'session_minutes' => 40,
            'characteristics' => 'Grupo activo que aprende mejor con juego, conversación y materiales concretos.',
        ]);

        $this->addDefaultPlanningSchedule($teacher, $group);

        $this->actingAs($teacher);

        $groupLabel = PlanningRequestResource::eligibleGroupOptions()[$group->id] ?? null;
        $this->assertNotNull($groupLabel);
        $this->assertStringContainsString('Preescolar (kínder)', $groupLabel);
        $this->assertStringContainsString('Tercer grado de preescolar', $groupLabel);

        $request = app(StartPlanningExperiment::class)->execute(
            $teacher,
            $group->id,
            '2026-09-21',
            '2026-09-25',
            'Exploración y comunicación',
            'Escenario E2E sintético de preescolar.',
        );

        app(SyncPlanningRequestSelections::class)->execute($teacher, $request, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);

        $confirmed = app(ConfirmPlanningRequest::class)->execute($teacher, $request->refresh());
        $snapshot = $confirmed->input_snapshot;

        $this->assertSame(PlanningRequestStatus::ESPERANDO_PAGO, $confirmed->status);
        $this->assertSame('preschool', $snapshot['curriculum']['curriculum']['educational_level']);
        $this->assertSame('Preescolar (kínder)', $snapshot['curriculum']['curriculum']['educational_level_label']);
        $this->assertSame('F2', $snapshot['curriculum']['phase']['code']);
        $this->assertSame('P3', $snapshot['curriculum']['grade']['code']);
        $this->assertSame('preschool_3', $snapshot['pedagogical_stage']['profile_key']);
        $this->assertSame(3, $snapshot['pedagogical_stage']['complexity_band']);
        $this->assertStringContainsString(
            'juego',
            mb_strtolower(implode(' ', $snapshot['pedagogical_stage']['planning_guardrails'])),
        );
        $this->assertStringContainsString(
            'niñas y niños',
            mb_strtolower(implode(' ', $snapshot['pedagogical_stage']['planning_guardrails'])),
        );
    }
}
