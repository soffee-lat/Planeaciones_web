<?php

namespace Tests\Feature;

use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Schedules\CreateGroupSubject;
use App\Actions\Schedules\EnsureDefaultGroupSubjects;
use App\Actions\Schedules\SaveGroupSchedule;
use App\Actions\Schedules\UpdateGroupSubjectColor;
use App\Models\CurricularContent;
use App\Models\GroupSubject;
use App\Models\Pda;
use App\Models\PlanningRequest;

class GroupSubjectCatalogTest extends PedagogyTestCase
{
    public function test_official_subject_catalog_is_built_from_group_curriculum(): void
    {
        $scene = $this->seedFullTeacher();

        app(EnsureDefaultGroupSubjects::class)->execute($scene['group']);

        $fields = $scene['version']->formativeFields()->orderBy('sort_order')->get();
        $subjects = GroupSubject::query()
            ->where('group_id', $scene['group']->id)
            ->where('origin', 'official')
            ->get();

        $this->assertCount($fields->count(), $subjects);

        foreach ($fields as $field) {
            $subject = $subjects->firstWhere('curriculum_field_code', $field->code);
            $this->assertNotNull($subject);
            $this->assertSame($field->name, $subject->name);
            $this->assertMatchesRegularExpression('/^#[0-9A-F]{6}$/', $subject->color);
        }
    }

    public function test_teacher_can_create_custom_subject_and_change_its_color(): void
    {
        $scene = $this->seedFullTeacher();

        $subject = app(CreateGroupSubject::class)->execute($scene['user'], $scene['group'], [
            'name' => 'Robótica',
            'color' => '#06B6D4',
        ]);

        $this->assertSame('custom', $subject->origin);
        $this->assertSame('Robótica', $subject->name);
        $this->assertSame('#06B6D4', $subject->color);

        $updated = app(UpdateGroupSubjectColor::class)->execute(
            $scene['user'],
            $scene['group'],
            $subject,
            '#EC4899',
        );

        $this->assertSame('#EC4899', $updated->color);
    }

    public function test_schedule_block_uses_catalog_subject_and_freezes_name_and_color(): void
    {
        $scene = $this->seedFullTeacher();

        $subject = app(CreateGroupSubject::class)->execute($scene['user'], $scene['group'], [
            'name' => 'Robótica',
            'color' => '#06B6D4',
        ]);

        $schedule = app(SaveGroupSchedule::class)->execute($scene['user'], $scene['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [[
                'day_of_week' => 1,
                'sequence' => 1,
                'starts_at' => '08:00',
                'ends_at' => '08:50',
                'label' => 'Texto manipulado por cliente',
                'group_subject_id' => $subject->id,
                'block_type' => 'class',
                'responsibility' => 'main_teacher',
                'include_in_planning' => true,
                'is_flexible' => false,
                'field_codes' => [],
                'notes' => null,
            ]],
        ]);

        $block = $schedule->blocks->firstOrFail();

        $this->assertSame($subject->id, $block->group_subject_id);
        $this->assertSame('Robótica', $block->label);
        $this->assertSame('Robótica', $block->subject_name_snapshot);
        $this->assertSame('#06B6D4', $block->subject_color_snapshot);
    }

    public function test_confirmation_freezes_subject_color_even_if_catalog_changes_later(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo participativo.',
        ])->save();

        $subject = app(CreateGroupSubject::class)->execute($scene['user'], $scene['group'], [
            'name' => 'Robótica',
            'color' => '#06B6D4',
        ]);

        app(SaveGroupSchedule::class)->execute($scene['user'], $scene['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [[
                'day_of_week' => 2,
                'sequence' => 1,
                'starts_at' => '08:00',
                'ends_at' => '08:50',
                'label' => 'Robótica',
                'group_subject_id' => $subject->id,
                'block_type' => 'class',
                'responsibility' => 'main_teacher',
                'include_in_planning' => true,
                'is_flexible' => false,
                'field_codes' => [],
                'notes' => null,
            ]],
        ]);

        $request = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-08',
        ]);

        $content = CurricularContent::query()
            ->where('curriculum_version_id', $scene['version']->id)
            ->whereHas('pdas', fn ($query) => $query->where('grade_id', $scene['grade']->id))
            ->firstOrFail();
        $pda = Pda::query()
            ->where('curricular_content_id', $content->id)
            ->where('grade_id', $scene['grade']->id)
            ->firstOrFail();

        app(SyncPlanningRequestSelections::class)->execute($scene['user'], $request, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);

        $confirmed = app(ConfirmPlanningRequest::class)->execute($scene['user'], $request->refresh());
        $snapshot = $confirmed->currentInputVersion->snapshot;

        $this->assertSame('Robótica', $snapshot['group']['schedule']['blocks'][0]['subject_name_snapshot']);
        $this->assertSame('#06B6D4', $snapshot['group']['schedule']['blocks'][0]['subject_color_snapshot']);
        $this->assertSame('#06B6D4', $snapshot['group']['planning_calendar'][0]['blocks'][0]['subject_color_snapshot']);

        app(UpdateGroupSubjectColor::class)->execute(
            $scene['user'],
            $scene['group'],
            $subject,
            '#EC4899',
        );

        $frozen = $confirmed->currentInputVersion->fresh()->snapshot;
        $this->assertSame('#06B6D4', $frozen['group']['schedule']['blocks'][0]['subject_color_snapshot']);
        $this->assertSame('#06B6D4', $frozen['group']['planning_calendar'][0]['blocks'][0]['subject_color_snapshot']);
    }
}
