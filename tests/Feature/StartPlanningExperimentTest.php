<?php

namespace Tests\Feature;

use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Schedules\EnsureDefaultGroupSubjects;
use App\Enums\PlanningRequestStatus;
use App\Enums\ProductEventType;
use App\Filament\App\Pages\StartPlanning;
use App\Models\GroupSubject;
use App\Models\ProductEvent;
use App\Models\PlanningRequest;
use Carbon\CarbonImmutable;
use Livewire\Livewire;

class StartPlanningExperimentTest extends PedagogyTestCase
{
    public function test_inicio_simplificado_crea_borrador_y_registra_evento(): void
    {
        $ctx = $this->seedFullTeacher();

        $request = app(StartPlanningExperiment::class)->execute(
            $ctx['user'],
            $ctx['group']->id,
            '2026-09-14',
            '2026-09-18',
            'Conociendo mi cuerpo',
            'Quiero integrar números y lenguaje.',
        );

        $this->assertSame($ctx['user']->id, $request->owner_id);
        $this->assertSame($ctx['group']->id, $request->group_id);
        $this->assertSame($ctx['version']->id, $request->curriculum_version_id);
        $this->assertSame($ctx['grade']->id, $request->grade_id);
        $this->assertSame('quick', $request->creation_mode);
        $this->assertSame('Conociendo mi cuerpo', $request->project);
        $this->assertSame('Quiero integrar números y lenguaje.', $request->topic);
        $this->assertSame(PlanningRequestStatus::BORRADOR, $request->status);

        $event = ProductEvent::query()->sole();
        $this->assertSame(ProductEventType::PlanningStarted, $event->event_type);
        $this->assertSame($request->id, $event->planning_request_id);
        $this->assertTrue($event->metadata['profile_reused']);
        $this->assertTrue($event->metadata['session_minutes_known']);
    }

    public function test_inicio_estructurado_crea_semanas_y_registra_metadata_permitida(): void
    {
        $ctx = $this->seedFullTeacher();
        app(EnsureDefaultGroupSubjects::class)->execute($ctx['group']);

        $subject = GroupSubject::query()
            ->where('group_id', $ctx['group']->id)
            ->where('is_active', true)
            ->firstOrFail();

        $request = app(StartPlanningExperiment::class)->execute(
            $ctx['user'],
            $ctx['group']->id,
            '2026-09-01',
            '2026-09-30',
            'Mi comunidad',
            'Reforzar lectura.',
            [
                'period_type' => 'month',
                'period_key' => '2026-09',
                'integrative_project' => 'Mi comunidad',
                'integrative_project_purpose' => 'Integrar aprendizajes del mes.',
                'context_note' => 'Reforzar lectura.',
                'weeks' => [
                    ['sequence' => 1, 'topics' => [['topic' => 'Tema 1', 'group_subject_id' => $subject->id]]],
                    ['sequence' => 2, 'topics' => [['topic' => 'Tema 2', 'group_subject_id' => $subject->id]]],
                    ['sequence' => 3, 'topics' => [['topic' => 'Tema 3', 'group_subject_id' => $subject->id]]],
                    ['sequence' => 4, 'topics' => [['topic' => 'Tema 4', 'group_subject_id' => $subject->id]]],
                    ['sequence' => 5, 'topics' => [['topic' => 'Tema 5', 'group_subject_id' => $subject->id]]],
                ],
            ],
        );

        $this->assertSame('month', $request->period_type);
        $this->assertSame('2026-09', $request->period_key);
        $this->assertCount(5, $request->planningWeeks);

        $event = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::PlanningStarted->value)
            ->sole();

        $this->assertSame('month', $event->metadata['period_type']);
        $this->assertTrue($event->metadata['structured_topics']);
    }

    public function test_inicio_simplificado_no_acepta_grupo_ajeno(): void
    {
        $a = $this->seedFullTeacher();
        $b = $this->seedFullTeacher();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PLANNING_EXPERIMENT_GROUP_NOT_ELIGIBLE');

        app(StartPlanningExperiment::class)->execute(
            $a['user'],
            $b['group']->id,
            '2026-09-14',
            '2026-09-18',
            'Tema válido',
        );
    }

    public function test_inicio_simplificado_no_acepta_grupo_con_perfil_incompleto(): void
    {
        $ctx = $this->seedFullTeacher();
        $ctx['profile']->forceFill(['student_count' => null])->save();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PLANNING_EXPERIMENT_GROUP_NOT_ELIGIBLE');

        app(StartPlanningExperiment::class)->execute(
            $ctx['user'],
            $ctx['group']->id,
            '2026-09-14',
            '2026-09-18',
            'Tema válido',
        );
    }

    public function test_calendario_semanal_selecciona_la_semana_desde_cualquier_dia_y_marca_ocupadas(): void
    {
        CarbonImmutable::setTestNow('2026-09-09 10:00:00');

        try {
            $ctx = $this->seedFullTeacher();
            $ctx['group']->forceFill(['school_year' => '2026-2027'])->save();

            PlanningRequest::factory()->create([
                'owner_id' => $ctx['user']->id,
                'group_id' => $ctx['group']->id,
                'curriculum_version_id' => $ctx['version']->id,
                'grade_id' => $ctx['grade']->id,
                'starts_on' => '2026-09-14',
                'ends_on' => '2026-09-18',
            ]);

            $this->actingAs($ctx['user']);

            $component = Livewire::test(StartPlanning::class)
                ->set('group_id', $ctx['group']->id)
                ->assertSet('calendar_month', '2026-09')
                ->call('selectCalendarDay', '2026-09-16')
                ->assertSet('period_key', '2026-09-14')
                ->assertSet('weeks.0.starts_on', '2026-09-14')
                ->assertSet('weeks.0.ends_on', '2026-09-18')
                ->assertSet('weeks.0.occupied', true);

            $calendar = $component->instance()->weekCalendar();
            $selectedDays = collect($calendar['days'])->where('selected', true)->values();

            $this->assertSame('14–18 sep 2026', $calendar['selected_week_label']);
            $this->assertTrue($calendar['selected_week_occupied']);
            $this->assertCount(5, $selectedDays);
            $this->assertTrue($selectedDays->every(fn (array $day): bool => $day['occupied']));
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_pagina_del_experimento_muestra_entrada_minima_y_reutilizacion_del_perfil(): void
    {
        $ctx = $this->seedFullTeacher();
        $this->actingAs($ctx['user']);

        $response = $this->get(StartPlanning::getUrl());

        $response->assertOk();
        $response->assertSee('¿Qué periodo vas a planear?');
        $response->assertSee('Tipo de planeación');
        $response->assertSee('Por semana');
        $response->assertSee('Por mes');
        $response->assertSee('Marca cualquier día de la semana escolar');
        $response->assertSee('Selecciona primero un grupo para ver su calendario escolar.');
        $response->assertDontSee('Modalidad');
    }
}
