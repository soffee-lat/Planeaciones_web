<?php

namespace Tests\Feature;

use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningPedagogicalStructure;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Schedules\CreateGroupSubject;
use App\Actions\Schedules\EnsureDefaultGroupSubjects;
use App\Actions\Schedules\SaveGroupSchedule;
use App\Models\CurricularContent;
use App\Models\GroupSubject;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\Planning\PlanningPeriodService;

class StructuredPlanningPeriodTest extends PedagogyTestCase
{
    public function test_month_is_split_into_real_partial_school_weeks(): void
    {
        $period = app(PlanningPeriodService::class)->resolve('month', '2026-09');

        $this->assertSame('2026-09-01', $period['starts_on']);
        $this->assertSame('2026-09-30', $period['ends_on']);
        $this->assertCount(5, $period['weeks']);

        $this->assertSame(
            [
                ['2026-09-01', '2026-09-04'],
                ['2026-09-07', '2026-09-11'],
                ['2026-09-14', '2026-09-18'],
                ['2026-09-21', '2026-09-25'],
                ['2026-09-28', '2026-09-30'],
            ],
            array_map(
                fn (array $week) => [$week['starts_on'], $week['ends_on']],
                $period['weeks'],
            ),
        );
    }

    public function test_week_period_is_predefined_monday_to_friday(): void
    {
        $period = app(PlanningPeriodService::class)->resolve('week', '2026-09-14');

        $this->assertSame('2026-09-14', $period['starts_on']);
        $this->assertSame('2026-09-18', $period['ends_on']);
        $this->assertCount(1, $period['weeks']);
    }

    public function test_structured_month_freezes_topics_and_marks_unassigned_subject_as_transversal(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo participativo.',
        ])->save();

        app(EnsureDefaultGroupSubjects::class)->execute($scene['group']);

        $official = GroupSubject::query()
            ->where('group_id', $scene['group']->id)
            ->where('origin', 'official')
            ->firstOrFail();

        $custom = app(CreateGroupSubject::class)->execute($scene['user'], $scene['group'], [
            'name' => 'Artes',
            'color' => '#F59E0B',
        ]);

        app(SaveGroupSchedule::class)->execute($scene['user'], $scene['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '10:00',
            'blocks' => [
                [
                    'day_of_week' => 2,
                    'sequence' => 1,
                    'starts_at' => '08:00',
                    'ends_at' => '08:50',
                    'label' => $official->name,
                    'group_subject_id' => $official->id,
                    'block_type' => 'class',
                    'responsibility' => 'main_teacher',
                    'include_in_planning' => true,
                    'is_flexible' => false,
                    'field_codes' => [],
                    'notes' => null,
                ],
                [
                    'day_of_week' => 2,
                    'sequence' => 2,
                    'starts_at' => '08:50',
                    'ends_at' => '09:40',
                    'label' => 'Artes',
                    'group_subject_id' => $custom->id,
                    'block_type' => 'class',
                    'responsibility' => 'main_teacher',
                    'include_in_planning' => true,
                    'is_flexible' => false,
                    'field_codes' => [],
                    'notes' => null,
                ],
            ],
        ]);

        $request = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'project' => 'Temporal',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-09-30',
        ]);

        $weeks = app(PlanningPeriodService::class)->resolve('month', '2026-09')['weeks'];
        $structuredWeeks = array_map(fn (array $week) => [
            'sequence' => $week['sequence'],
            'topics' => [[
                'topic' => 'Números hasta 10,000',
                'group_subject_id' => $official->id,
                'notes' => null,
            ]],
        ], $weeks);

        $request = app(SyncPlanningPedagogicalStructure::class)->execute(
            $scene['user'],
            $request,
            [
                'period_type' => 'month',
                'period_key' => '2026-09',
                'integrative_project' => 'Mi comunidad',
                'integrative_project_purpose' => 'Relacionar aprendizajes de distintas materias.',
                'weeks' => $structuredWeeks,
            ],
        );

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

        $this->assertSame('month', $snapshot['pedagogical_structure']['period_type']);
        $this->assertSame('Mi comunidad', $snapshot['pedagogical_structure']['integrative_project']['name']);
        $this->assertSame('Números hasta 10,000', $snapshot['pedagogical_structure']['weeks'][0]['topics'][0]['topic']);

        $firstDay = collect($snapshot['group']['planning_calendar'])
            ->firstWhere('date', '2026-09-01');

        $this->assertNotNull($firstDay);
        $this->assertSame('Números hasta 10,000', $firstDay['blocks'][0]['primary_topics'][0]['topic']);
        $this->assertFalse($firstDay['blocks'][0]['transversal']);
        $this->assertTrue($firstDay['blocks'][1]['transversal']);
        $this->assertSame('Números hasta 10,000', $firstDay['blocks'][1]['available_transversal_topics'][0]['topic']);
    }
}
