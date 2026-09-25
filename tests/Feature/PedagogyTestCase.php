<?php

namespace Tests\Feature;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Actions\Schedules\SaveGroupSchedule;
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

    protected function addDefaultPlanningSchedule(User $user, Group $group): void
    {
        $group = $group->fresh(['activeSchedule.blocks']) ?? $group;

        $hasUsableSchedule = $group->activeSchedule?->blocks
            ->contains(fn ($block): bool => (bool) $block->include_in_planning
                && ! in_array($block->block_type, ['break', 'unavailable'], true))
            ?? false;

        if ($hasUsableSchedule) {
            return;
        }

        app(SaveGroupSchedule::class)->execute($user, $group, [
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [[
                'day_of_week' => 1,
                'sequence' => 1,
                'starts_at' => '08:00',
                'ends_at' => '08:50',
                'label' => 'Lenguajes',
                'block_type' => 'class',
                'responsibility' => 'main_teacher',
                'include_in_planning' => true,
                'is_flexible' => false,
                'field_codes' => [],
                'notes' => null,
            ]],
        ]);
    }

    /**
     * Publish a full valid curriculum tree and mark version 1 selectable.
     * Returns array{curriculum:Curriculum, version:CurriculumVersion, grade:Grade, otherGrade:Grade}
     *
     * @return array{curriculum:Curriculum, version:CurriculumVersion, grade:Grade, otherGrade:Grade, draftVersion:CurriculumVersion, draftGrade:Grade}
     */
    protected function seedPublishedCurriculum(): array
    {
        $admin = $this->admin();
        $curriculum = Curriculum::factory()->create([
            'code' => 'MX-NEM-PRIMARY-TEST-' . uniqid(),
            'educational_level' => 'primary',
        ]);
        $version = CurriculumVersion::factory()->create(['curriculum_id' => $curriculum->id, 'number' => 1]);
        $phaseA = EducationalPhase::factory()->create(['curriculum_version_id' => $version->id, 'code' => 'F3', 'name' => 'Fase 3']);
        $phaseB = EducationalPhase::factory()->create(['curriculum_version_id' => $version->id, 'code' => 'F4', 'name' => 'Fase 4']);
        $gradeA = Grade::factory()->create(['curriculum_version_id' => $version->id, 'educational_phase_id' => $phaseA->id, 'code' => 'G1', 'name' => 'Primer grado', 'ordinal' => 1]);
        $gradeB = Grade::factory()->create(['curriculum_version_id' => $version->id, 'educational_phase_id' => $phaseB->id, 'code' => 'G3', 'name' => 'Tercer grado', 'ordinal' => 3]);
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
        $draft = CurriculumVersion::factory()->create(['curriculum_id' => $curriculum->id, 'number' => 2]);
        $draftPhase = EducationalPhase::factory()->create(['curriculum_version_id' => $draft->id, 'code' => 'F3', 'name' => 'Fase 3']);
        $draftGrade = Grade::factory()->create(['curriculum_version_id' => $draft->id, 'educational_phase_id' => $draftPhase->id, 'code' => 'G1', 'name' => 'Primer grado', 'ordinal' => 1]);

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
