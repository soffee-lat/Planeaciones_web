<?php

namespace Tests\Feature;

use App\Actions\Planning\StartPlanningExperiment;
use App\Enums\ProductEventType;
use App\Models\PlanningRequest;

class StartPlanningExperienceTest extends PedagogyTestCase
{
    public function test_start_page_is_available_without_asking_for_output_format(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['user']->forceFill(['onboarding_completed_at' => now()])->save();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo activo.',
        ])->save();

        $this->actingAs($scene['user']->refresh());
        $response = $this->get('/app/nueva-planeacion');

        $response->assertOk();
        $response->assertSee('Prepara tu planeación');
        $response->assertDontSee('Formato de salida');
        $response->assertSee('El formato se elegirá sólo cuando la planeación esté lista para exportarse.', false);
        $response->assertSee('Continuar con el currículo');
    }

    public function test_quick_start_creates_format_agnostic_request(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo activo.',
        ])->save();

        $request = app(StartPlanningExperiment::class)->execute(
            $scene['user'],
            $scene['group']->id,
            '2026-09-15',
            '2026-09-19',
            'La Independencia de México',
            'Integrar actividades lúdicas.',
        );

        $this->assertInstanceOf(PlanningRequest::class, $request);
        $this->assertNull($request->format_version_id);
        $this->assertSame('La Independencia de México', $request->project);
        $this->assertSame('Integrar actividades lúdicas.', $request->topic);

        $event = $request->productEvents()
            ->where('event_type', ProductEventType::PlanningStarted->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertArrayNotHasKey('format_version_id', $event->metadata ?? []);
    }
}
