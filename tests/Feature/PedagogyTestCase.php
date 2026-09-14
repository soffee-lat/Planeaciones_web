<?php

namespace Tests\Feature;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Enums\RoleCode;
use App\Enums\SchoolType;
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
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

abstract class PedagogyTestCase extends TestCase
{
    use RefreshDatabase;

    protected function customer(): User
    {
        return User::factory()->withRole(RoleCode::Customer)->create();
    }

    protected function admin(): User
    {
        return User::factory()->withRole(RoleCode::Administrator)->create();
    }

    protected function reviewer(): User
    {
        return User::factory()->withRole(RoleCode::Reviewer)->create();
    }

    /**
     * Publish a full valid curriculum tree and mark version 1 selectable.
     *
     * This fixture intentionally represents a production-eligible curriculum.
     * DEMO factories remain available for tests that explicitly exercise
     * fictitious/editorial catalogs, but teacher planning tests must not use
     * them now that production flows reject DEMO data by design.
     *
     * @return array{curriculum:Curriculum, version:CurriculumVersion, grade:Grade, otherGrade:Grade, draftVersion:CurriculumVersion, draftGrade:Grade}
     */
    protected function seedPublishedCurriculum(): array
    {
        $admin = $this->admin();
        $curriculum = Curriculum::factory()->create([
            'code' => 'TEST-PRODUCTION-' . fake()->unique()->numerify('####'),
            'name' => 'Currículo de prueba apto para planeación',
            'country_code' => 'MX',
            'educational_level' => 'primaria',
            'description' => 'Fixture automatizado para validar flujos pedagógicos publicados.',
            'selectable_version_id' => null,
        ]);
        $version = CurriculumVersion::factory()->create([
            'curriculum_id' => $curriculum->id,
            'number' => 1,
            'label' => 'TEST-PUBLISHED-1',
            'source_reference' => 'fixture://pedagogy-test/published-v1',
        ]);

        $phaseA = EducationalPhase::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-A',
            'name' => 'Fase de prueba A',
            'description' => 'Primera fase del fixture automatizado.',
        ]);
        $phaseB = EducationalPhase::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-B',
            'name' => 'Fase de prueba B',
            'description' => 'Segunda fase del fixture automatizado.',
        ]);

        $gradeA = Grade::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phaseA->id,
            'code' => 'GR-A',
            'name' => 'Primer grado de prueba',
            'ordinal' => 1,
        ]);
        $gradeB = Grade::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phaseB->id,
            'code' => 'GR-B',
            'name' => 'Segundo grado de prueba',
            'ordinal' => 2,
        ]);

        $field = FormativeField::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'FF-1',
            'name' => 'Lenguajes de prueba',
            'description' => 'Campo curricular del fixture automatizado.',
        ]);

        $contentA = CurricularContent::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phaseA->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-A',
            'title' => 'Comunicación oral y escrita',
            'full_text' => 'Contenido curricular de prueba para validar el flujo canónico de planeación.',
            'source_locator' => 'fixture://pedagogy-test/content-a',
        ]);
        $contentB = CurricularContent::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phaseB->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-B',
            'title' => 'Comprensión y producción de textos',
            'full_text' => 'Segundo contenido curricular de prueba para validar relaciones entre fase y grado.',
            'source_locator' => 'fixture://pedagogy-test/content-b',
        ]);

        Pda::factory()->create([
            'curriculum_version_id' => $version->id,
            'curricular_content_id' => $contentA->id,
            'grade_id' => $gradeA->id,
            'code' => 'PDA-A',
            'full_text' => 'Expresa ideas y recupera información relevante en actividades de comunicación.',
            'source_locator' => 'fixture://pedagogy-test/pda-a',
        ]);
        Pda::factory()->create([
            'curriculum_version_id' => $version->id,
            'curricular_content_id' => $contentB->id,
            'grade_id' => $gradeB->id,
            'code' => 'PDA-B',
            'full_text' => 'Organiza información y produce textos acordes con una situación comunicativa.',
            'source_locator' => 'fixture://pedagogy-test/pda-b',
        ]);

        ArticulatingAxis::factory()->create([
            'curriculum_version_id' => $version->id,
            'code' => 'AX-1',
            'name' => 'Pensamiento crítico de prueba',
            'description' => 'Eje del fixture automatizado para validar selección curricular.',
        ]);

        app(PublishCurriculumVersion::class)($version, $admin);
        $curriculum->fill(['selectable_version_id' => $version->id])->save();

        // Second (draft) version + its own grade for negative tests.
        $draft = CurriculumVersion::factory()->create([
            'curriculum_id' => $curriculum->id,
            'number' => 2,
            'label' => 'TEST-DRAFT-2',
            'source_reference' => 'fixture://pedagogy-test/draft-v2',
        ]);
        $draftPhase = EducationalPhase::factory()->create([
            'curriculum_version_id' => $draft->id,
            'code' => 'PH-D',
            'name' => 'Fase borrador de prueba',
            'description' => 'Fase usada exclusivamente en pruebas negativas de publicación.',
        ]);
        $draftGrade = Grade::factory()->create([
            'curriculum_version_id' => $draft->id,
            'educational_phase_id' => $draftPhase->id,
            'code' => 'GR-D',
            'name' => 'Grado borrador de prueba',
            'ordinal' => 1,
        ]);

        return [
            'curriculum' => $curriculum->refresh(),
            'version' => $version->refresh(),
            'grade' => $gradeA,
            'otherGrade' => $gradeB,
            'draftVersion' => $draft,
            'draftGrade' => $draftGrade,
        ];
    }

    /** @return array{user:User, school:School, group:Group, profile:GroupProfile, grade:Grade, version:CurriculumVersion} */
    protected function seedFullTeacher(?User $user = null): array
    {
        $ctx = $this->seedPublishedCurriculum();
        $user ??= $this->customer();
        $school = School::factory()->create([
            'owner_id' => $user->id,
            'school_type' => SchoolType::Public->value,
        ]);
        $group = Group::create([
            'owner_id' => $user->id,
            'school_id' => $school->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
            'name' => 'Grupo A',
            'school_year' => '2026-2027',
        ]);
        $profile = GroupProfile::factory()->create(['group_id' => $group->id]);

        return [
            'user' => $user,
            'school' => $school,
            'group' => $group,
            'profile' => $profile,
            'grade' => $ctx['grade'],
            'otherGrade' => $ctx['otherGrade'],
            'version' => $ctx['version'],
            'draftVersion' => $ctx['draftVersion'],
            'draftGrade' => $ctx['draftGrade'],
            'curriculum' => $ctx['curriculum'],
        ];
    }
}
