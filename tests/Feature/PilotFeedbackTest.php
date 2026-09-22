<?php

namespace Tests\Feature;

use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Validation\SubmitPilotFeedback;
use App\Enums\ProductEventType;
use App\Models\PlanningFeedback;
use App\Models\ProductEvent;
use App\Services\Analytics\PilotBehaviorSummary;
use App\Services\Analytics\ProductEventRecorder;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesRenderedPlanningScenario;

class PilotFeedbackTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesRenderedPlanningScenario;

    private function deliveredQuickScene(): array
    {
        $scene = $this->renderedPlanningScene('documents/pilot-feedback');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);

        // El helper histórico crea solicitudes advanced. Para aislar este test
        // de V4 marcamos la solicitud ya entregada como perteneciente al flujo
        // quick; este campo no forma parte del snapshot comercial inmutable.
        DB::table('planning_requests')->where('id', $scene['request']->id)->update(['creation_mode' => 'quick']);
        $scene['request'] = $scene['request']->fresh();
        $scene['delivery'] = $delivery;

        return $scene;
    }

    public function test_docente_envia_tres_respuestas_y_se_registra_completion_event(): void
    {
        $scene = $this->deliveredQuickScene();

        $response = $this->actingAs($scene['request']->owner)->post(
            route('planning.feedback.store', ['planningRequest' => $scene['request']->id]),
            [
                'saved_time_bucket' => '60_120',
                'most_helpful' => 'curriculum',
                'next_real_planning' => 'yes',
            ],
        );

        $response->assertRedirect();
        $feedback = PlanningFeedback::query()->sole();
        $this->assertSame($scene['request']->id, $feedback->planning_request_id);
        $this->assertSame($scene['delivery']->id, $feedback->delivery_id);
        $this->assertSame('60_120', $feedback->saved_time_bucket);
        $this->assertSame('curriculum', $feedback->most_helpful);
        $this->assertSame('yes', $feedback->next_real_planning);

        $event = ProductEvent::query()
            ->where('planning_request_id', $scene['request']->id)
            ->where('event_type', ProductEventType::PlanningCompleted->value)
            ->sole();
        $this->assertSame($scene['delivery']->id, $event->metadata['delivery_id']);
        $this->assertTrue($event->metadata['feedback_submitted']);
    }

    public function test_feedback_solo_aparece_en_flujo_quick_entregado_y_luego_muestra_respuestas(): void
    {
        $scene = $this->deliveredQuickScene();
        $owner = $scene['request']->owner;

        $this->actingAs($owner)
            ->get('/app/planning-requests/' . $scene['request']->id)
            ->assertOk()
            ->assertSee('Ayúdanos a mejorar')
            ->assertSee('¿Cuánto tiempo te ahorró?')
            ->assertSee('¿La usarías nuevamente?');

        app(SubmitPilotFeedback::class)->execute($owner, $scene['request'], '30_60', 'activities', 'maybe');

        $this->actingAs($owner)
            ->get('/app/planning-requests/' . $scene['request']->id)
            ->assertOk()
            ->assertSee('Gracias por ayudarnos a mejorar')
            ->assertSee('Tus respuestas ya quedaron registradas.')
            ->assertSee('Encuesta respondida');
    }

    public function test_otro_docente_no_puede_enviar_feedback_y_respuesta_es_inmutable(): void
    {
        $scene = $this->deliveredQuickScene();
        $other = $this->customer();

        $this->actingAs($other)->post(
            route('planning.feedback.store', ['planningRequest' => $scene['request']->id]),
            [
                'saved_time_bucket' => 'under_30',
                'most_helpful' => 'assessment',
                'next_real_planning' => 'no',
            ],
        )->assertNotFound();

        $feedback = app(SubmitPilotFeedback::class)->execute(
            $scene['request']->owner,
            $scene['request'],
            'over_120',
            'institutional_format',
            'yes',
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PLANNING_FEEDBACK_IMMUTABLE');
        $feedback->forceFill(['next_real_planning' => 'no'])->save();
    }

    public function test_resumen_detecta_retorno_y_tiempos_entre_hitos(): void
    {
        $ctx = $this->seedFullTeacher();
        $first = app(StartPlanningExperiment::class)->execute(
            $ctx['user'], $ctx['group']->id, '2026-09-14', '2026-09-18', 'Tema uno',
        );
        $this->travel(10)->seconds();
        app(ProductEventRecorder::class)->record(
            $ctx['user'],
            ProductEventType::CurriculumMapConfirmed,
            request: $first,
            metadata: [
                'selection_revision' => 1,
                'fingerprint' => str_repeat('a', 64),
                'content_count' => 1,
                'pda_count' => 1,
                'axis_count' => 0,
            ],
        );
        $this->travel(20)->seconds();
        app(ProductEventRecorder::class)->record(
            $ctx['user'],
            ProductEventType::PlanGenerated,
            request: $first,
            metadata: ['document_version_id' => 99, 'renderer' => 'canonical'],
        );

        $second = app(StartPlanningExperiment::class)->execute(
            $ctx['user'], $ctx['group']->id, '2026-09-21', '2026-09-25', 'Tema dos',
        );

        $rows = app(PilotBehaviorSummary::class)->rows($ctx['user']->id);

        $this->assertCount(2, $rows);
        $this->assertSame($first->id, $rows[0]['request_id']);
        $this->assertSame(1, $rows[0]['attempt']);
        $this->assertFalse($rows[0]['repeat_planning']);
        $this->assertSame(10, $rows[0]['seconds_to_map']);
        $this->assertSame(30, $rows[0]['seconds_to_generation']);
        $this->assertSame($second->id, $rows[1]['request_id']);
        $this->assertSame(2, $rows[1]['attempt']);
        $this->assertTrue($rows[1]['repeat_planning']);
    }
}
