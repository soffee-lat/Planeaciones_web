<?php

namespace Tests\Feature;

use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Planning\StartPlanningExperiment;
use App\Enums\ProductEventType;
use App\Filament\App\Pages\StartPlanning;
use App\Models\PlanningRequest;
use RuntimeException;
use Tests\Concerns\CreatesInstitutionalFormatScenario;

class StartPlanningExperienceTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_start_page_is_available_for_customer_and_mentions_format_selection(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['user']->forceFill(['onboarding_completed_at' => now()])->save();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo activo.',
        ])->save();

        app(EnsureStandardFormat::class)->execute();
        $this->publishedInstitutionalFormat($scene['user']->id);

        $this->actingAs($scene['user']->refresh());
        $response = $this->get('/app/nueva-planeacion');

        $response->assertOk();
        $response->assertSee('Prepara tu planeación');
        $response->assertSee('Formato de salida');
        $response->assertSee('Continuar con el currículo');
    }

    public function test_quick_start_persists_selected_format_version(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo activo.',
        ])->save();
        $format = $this->publishedInstitutionalFormat($scene['user']->id);

        $request = app(StartPlanningExperiment::class)->execute(
            $scene['user'],
            $scene['group']->id,
            $format->id,
            '2026-09-15',
            '2026-09-19',
            'La Independencia de México',
            'Integrar actividades lúdicas.',
        );

        $this->assertInstanceOf(PlanningRequest::class, $request);
        $this->assertSame($format->id, $request->format_version_id);
        $this->assertSame('La Independencia de México', $request->project);
        $this->assertSame('Integrar actividades lúdicas.', $request->topic);

        $event = $request->productEvents()
            ->where('event_type', ProductEventType::PlanningStarted->value)
            ->latest('id')
            ->firstOrFail();

        $this->assertSame($format->id, (int) data_get($event->metadata, 'format_version_id'));
    }

    public function test_quick_start_rejects_foreign_format_version(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo activo.',
        ])->save();
        $foreign = $this->publishedInstitutionalFormat($this->customer()->id);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLANNING_EXPERIMENT_FORMAT_NOT_ELIGIBLE');

        app(StartPlanningExperiment::class)->execute(
            $scene['user'],
            $scene['group']->id,
            $foreign->id,
            '2026-09-15',
            '2026-09-19',
            'La Independencia de México',
        );
    }
}
