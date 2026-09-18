<?php

namespace Tests\Feature;

use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Schedules\SaveGroupSchedule;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\Planning\PlanningCalendarBuilder;

class GroupScheduleTest extends PedagogyTestCase
{
    public function test_schedule_is_visible_from_group_and_editor_page_is_accessible(): void
    {
        $scene = $this->seedFullTeacher();

        $this->actingAs($scene['user'])
            ->get(GroupResource::getUrl('edit', ['record' => $scene['group']->getKey()]))
            ->assertOk()
            ->assertSee('Horario');

        $this->actingAs($scene['user'])
            ->get(GroupResource::getUrl('schedule', ['record' => $scene['group']->getKey()]))
            ->assertOk()
            ->assertSee('Horario')
            ->assertSee('Armemos tu semana escolar')
            ->assertSee('Define tu jornada')
            ->assertSee('Entrada')
            ->assertSee('Salida')
            ->assertSee('Crear mi horario')
            ->assertSee('Toca cualquier espacio para agregar una clase.');
    }

    public function test_teacher_can_save_visual_schedule_and_calendar_expands_real_dates(): void
    {
        $scene = $this->seedFullTeacher();
        $schedule = app(SaveGroupSchedule::class)->execute($scene['user'], $scene['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [
                [
                    'day_of_week' => 1,
                    'sequence' => 1,
                    'starts_at' => '08:00',
                    'ends_at' => '08:50',
                    'label' => 'Matemáticas',
                    'block_type' => 'class',
                    'responsibility' => 'main_teacher',
                    'include_in_planning' => true,
                    'is_flexible' => false,
                    'field_codes' => [],
                    'notes' => null,
                ],
                [
                    'day_of_week' => 1,
                    'sequence' => 2,
                    'starts_at' => '08:50',
                    'ends_at' => '09:40',
                    'label' => 'Educación Física',
                    'block_type' => 'specialist',
                    'responsibility' => 'specialist',
                    'include_in_planning' => false,
                    'is_flexible' => false,
                    'field_codes' => [],
                    'notes' => 'La imparte otro profesor.',
                ],
                [
                    'day_of_week' => 2,
                    'sequence' => 1,
                    'starts_at' => '08:00',
                    'ends_at' => '09:10',
                    'label' => 'Proyecto',
                    'block_type' => 'flexible',
                    'responsibility' => 'main_teacher',
                    'include_in_planning' => true,
                    'is_flexible' => true,
                    'field_codes' => [],
                    'notes' => null,
                ],
            ],
        ]);

        $this->assertSame(1, $schedule->revision);
        $this->assertCount(3, $schedule->blocks);

        $calendar = app(PlanningCalendarBuilder::class)->build($schedule, '2026-09-01', '2026-09-08');

        // 1 y 8 son martes; 7 es lunes.
        $this->assertSame(['2026-09-01', '2026-09-07', '2026-09-08'], array_column($calendar, 'date'));
        $this->assertSame(70, $calendar[0]['blocks'][0]['minutes']);
        $this->assertFalse($calendar[1]['blocks'][1]['include_in_planning']);
        $this->assertSame('specialist', $calendar[1]['blocks'][1]['responsibility']);
    }

    public function test_confirmation_freezes_schedule_and_expanded_calendar_in_input_snapshot(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo participativo.',
        ])->save();

        app(SaveGroupSchedule::class)->execute($scene['user'], $scene['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [[
                'day_of_week' => 2,
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
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $scene['grade']->id))
            ->firstOrFail();
        $pda = Pda::query()->where('curricular_content_id', $content->id)
            ->where('grade_id', $scene['grade']->id)->firstOrFail();

        app(SyncPlanningRequestSelections::class)->execute($scene['user'], $request, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);

        $confirmed = app(ConfirmPlanningRequest::class)->execute($scene['user'], $request->refresh());
        $snapshot = $confirmed->currentInputVersion->snapshot;

        $this->assertSame(1, $snapshot['group']['schedule']['revision']);
        $this->assertSame(['2026-09-01', '2026-09-08'], array_column($snapshot['group']['planning_calendar'], 'date'));
        $this->assertSame('Lenguajes', $snapshot['group']['planning_calendar'][0]['blocks'][0]['label']);

        // Una edición posterior crea una nueva revisión, pero no altera el snapshot confirmado.
        app(SaveGroupSchedule::class)->execute($scene['user'], $scene['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [[
                'day_of_week' => 2,
                'sequence' => 1,
                'starts_at' => '08:00',
                'ends_at' => '09:00',
                'label' => 'English',
                'block_type' => 'class',
                'responsibility' => 'specialist',
                'include_in_planning' => false,
                'is_flexible' => false,
                'field_codes' => [],
                'notes' => null,
            ]],
        ]);

        $this->assertSame('Lenguajes', $confirmed->currentInputVersion->fresh()->snapshot['group']['schedule']['blocks'][0]['label']);
        $this->assertSame(1, $confirmed->currentInputVersion->fresh()->snapshot['group']['schedule']['revision']);
    }
}
