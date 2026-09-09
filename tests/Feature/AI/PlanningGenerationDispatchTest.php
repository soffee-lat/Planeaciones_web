<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\AiExecutionStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\RequestStateEvent;
use App\Models\UsageReservation;
use Filament\Facades\Filament;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningGenerationDispatchTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    private function readyRequest(array $limits = []): PlanningRequest
    {
        $request = $this->draft();
        $this->period($request, $limits);
        $request = $this->confirm($request);
        return $this->authorize($request)->fresh(['usageReservations']);
    }

    private function publishGenerationPrompt(array $overrides = []): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.generation',
            'category' => PromptCategory::Generation->value,
        ]);
        $version = PromptVersion::factory()->create(array_merge([
            'template_id' => $template->id,
            'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
        ], $overrides));

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }

    public function test_dispatch_transiciona_consume_planning_y_crea_execution_outbox_atomicos(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $prompt = $this->publishGenerationPrompt();
        $correlationId = '11111111-1111-4111-8111-111111111111';

        $execution = app(DispatchPlanningGeneration::class)->execute($request, $correlationId);
        $request = $request->fresh(['usageReservations']);

        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->status);
        $this->assertSame(AiExecutionStatus::Pending, $execution->status);
        $this->assertSame($prompt->id, $execution->prompt_version_id);
        $this->assertSame($request->input_revision, $execution->input_revision);
        $this->assertNull($execution->provider);
        $this->assertNull($execution->model);

        $planning = $request->usageReservations->firstWhere('resource', UsageResource::Planning);
        $this->assertSame(UsageReservationStatus::Consumed, $planning->status);
        $this->assertNotNull($planning->consumed_at);

        $event = OutboxEvent::query()->sole();
        $this->assertNull($event->published_at);
        $this->assertSame($request->id, $event->aggregate_id);
        $this->assertSame($execution->id, $event->payload['ai_execution_id']);
        $this->assertSame($correlationId, $event->payload['correlation_id']);

        $state = RequestStateEvent::query()->where('request_id', $request->id)->latest('id')->firstOrFail();
        $this->assertSame('LISTA_PARA_PROCESAR', $state->from_status);
        $this->assertSame('GENERACION_IA', $state->to_status);
        $this->assertSame('system', $state->actor_type);
        $this->assertSame($correlationId, $state->correlation_id);
    }

    public function test_human_review_permanece_reservada_al_iniciar_generacion(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest([
            'human_review_required' => true,
            'human_review_limit' => 8,
        ]);
        $this->publishGenerationPrompt();

        app(DispatchPlanningGeneration::class)->execute($request);

        $human = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $human->status);
        $this->assertNull($human->consumed_at);
    }

    public function test_reintentar_dispatch_es_idempotente_sin_doble_consumo_evento_o_execution(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();

        $first = app(DispatchPlanningGeneration::class)->execute($request, '22222222-2222-4222-8222-222222222222');
        $consumedAt = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->consumed_at;

        $second = app(DispatchPlanningGeneration::class)->execute($request->fresh(), '33333333-3333-4333-8333-333333333333');

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, AiExecution::query()->where('request_id', $request->id)->count());
        $this->assertSame(1, OutboxEvent::query()->where('aggregate_id', $request->id)->count());
        $this->assertSame(1, RequestStateEvent::query()->where('request_id', $request->id)->where('to_status', 'GENERACION_IA')->count());
        $this->assertEquals($consumedAt, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->consumed_at);
    }

    public function test_sin_prompt_activo_no_consume_ni_cambia_estado(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();

        try {
            app(DispatchPlanningGeneration::class)->execute($request);
            $this->fail('Debió rechazar la generación sin prompt activo.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_GENERATION_ACTIVE_PROMPT_MISSING', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $reservation = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $reservation->status);
        $this->assertSame(0, AiExecution::query()->where('request_id', $request->id)->count());
        $this->assertSame(0, OutboxEvent::query()->where('aggregate_id', $request->id)->count());
    }

    public function test_modo_api_no_configurado_falla_antes_de_tocar_derechos(): void
    {
        config(['ai.mode' => 'api']);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();

        try {
            app(DispatchPlanningGeneration::class)->execute($request);
            $this->fail('Debió rechazar API sin proveedor.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_API_PROVIDER_NOT_CONFIGURED', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $reservation = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $reservation->status);
    }


    public function test_cliente_solo_ve_estado_publico_preparando_sin_metadatos_ia(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();
        $execution = app(DispatchPlanningGeneration::class)->execute($request);

        $this->actingAs($request->owner)
            ->get('/app/planning-requests/' . $request->id)
            ->assertOk()
            ->assertSee('Preparando')
            ->assertDontSee('GENERACION_IA')
            ->assertDontSee($execution->operation_key)
            ->assertDontSee('prompt_version_id')
            ->assertDontSee('rendered_prompt_hash');
    }

    public function test_configuracion_manual_no_privada_falla_antes_de_consumir(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'missing-private-disk',
        ]);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();

        try {
            app(DispatchPlanningGeneration::class)->execute($request);
            $this->fail('Debió rechazar disco privado inexistente.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $this->assertSame(UsageReservationStatus::Reserved, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->status);
        $this->assertSame(0, AiExecution::query()->where('request_id', $request->id)->count());
    }


    public function test_prefijo_manual_invalido_falla_antes_de_consumir(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.prefix' => str_repeat('x', 901),
        ]);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();

        try {
            app(DispatchPlanningGeneration::class)->execute($request);
            $this->fail('Debió rechazar un prefijo que no cabe en el contrato persistido.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_MANUAL_PREFIX_INVALID', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $this->assertSame(UsageReservationStatus::Reserved, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->status);
        $this->assertSame(0, AiExecution::query()->where('request_id', $request->id)->count());
    }

    public function test_prompt_con_variable_no_soportada_falla_antes_de_consumir(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt([
            'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}} EXTRA={{secret_variable}}',
            'allowed_variables' => ['input_snapshot', 'output_schema', 'secret_variable'],
        ]);

        try {
            app(DispatchPlanningGeneration::class)->execute($request);
            $this->fail('Debió rechazar variable de prompt no soportada.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_PROMPT_VARIABLE_UNSUPPORTED', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $reservation = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Reserved, $reservation->status);
        $this->assertSame(0, AiExecution::query()->where('request_id', $request->id)->count());
        $this->assertSame(0, OutboxEvent::query()->where('aggregate_id', $request->id)->count());
    }

    public function test_prompt_con_schema_distinto_falla_antes_de_consumir(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt(['output_schema' => ['type' => 'object']]);

        try {
            app(DispatchPlanningGeneration::class)->execute($request);
            $this->fail('Debió rechazar schema distinto al contrato versionado.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_GENERATION_PROMPT_SCHEMA_MISMATCH', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
        $this->assertSame(UsageReservationStatus::Reserved, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole()->status);
    }

    public function test_correlation_id_invalido_se_rechaza_sin_efectos(): void
    {
        config(['ai.mode' => 'manual']);
        $request = $this->readyRequest();
        $this->publishGenerationPrompt();

        $this->expectException(AiPipelineException::class);
        $this->expectExceptionMessage('AI_CORRELATION_ID_INVALID');
        app(DispatchPlanningGeneration::class)->execute($request, 'not-a-uuid');
    }
}
