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
        $phaseA = EducationalPhase::factory()->create(['curriculum_version_id' => $version->id, 'code' => 'PH-A']);
        $phaseB = EducationalPhase::factory()->create(['curriculum_version_id' => $version->id, 'code' => 'PH-B']);
        $gradeA = Grade::factory()->create(['curriculum_version_id' => $version->id, 'educational_phase_id' => $phaseA->id, 'code' => 'GR-A', 'ordinal' => 1]);
        $gradeB = Grade::factory()->create(['curriculum_version_id' => $version->id, 'educational_phase_id' => $phaseB->id, 'code' => 'GR-B', 'ordinal' => 2]);
        $field = FormativeField::factory()->create(['curriculum_version_id' => $version->id, 'code' => 'FF-1']);
        $contentA = CurricularContent::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phaseA->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-A',
        ]);
        $contentB = CurricularContent::factory()->create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phaseB->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-B',
        ]);
        Pda::factory()->create(['curriculum_version_id' => $version->id, 'curricular_content_id' => $contentA->id, 'grade_id' => $gradeA->id, 'code' => 'PDA-A']);
        Pda::factory()->create(['curriculum_version_id' => $version->id, 'curricular_content_id' => $contentB->id, 'grade_id' => $gradeB->id, 'code' => 'PDA-B']);
        ArticulatingAxis::factory()->create(['curriculum_version_id' => $version->id, 'code' => 'AX-1']);

        app(PublishCurriculumVersion::class)($version, $admin);
        $curriculum->fill(['selectable_version_id' => $version->id])->save();

        // Second (draft) version + its own grade for negative tests.
        $draft = CurriculumVersion::factory()->create([
            'curriculum_id' => $curriculum->id,
            'number' => 2,
            'label' => 'TEST-DRAFT-2',
            'source_reference' => 'fixture://pedagogy-test/draft-v2',
        ]);
        $draftPhase = EducationalPhase::factory()->create(['curriculum_version_id' => $draft->id, 'code' => 'PH-D']);
        $draftGrade = Grade::factory()->create(['curriculum_version_id' => $draft->id, 'educational_phase_id' => $draftPhase->id, 'code' => 'GR-D', 'ordinal' => 1]);

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
