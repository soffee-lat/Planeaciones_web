<?php

namespace Tests\Feature\Planning;

use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Planning\RejectClientCorrection;
use App\Actions\Planning\RequestClientCorrection;
use App\Actions\Planning\StartClientCorrection;
use App\Actions\Planning\WithdrawClientCorrection;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\CorrectionRequestStatus;
use App\Enums\OperationalNotificationType;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\ClientCorrectionException;
use App\Models\AiExecution;
use App\Models\CorrectionRequest;
use App\Models\DocumentVersion;
use App\Models\OperationalNotificationEvent;
use App\Models\OutboxEvent;
use App\Models\PlanningDelivery;
use App\Models\UsageReservation;
use App\Filament\Admin\Pages\ClientCorrections;
use App\Services\AI\CorrectionInputBuilder;
use Illuminate\Auth\Access\AuthorizationException;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesRenderedPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class ClientCorrectionTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesRenderedPlanningScenario;

    /** @return array{request:\App\Models\PlanningRequest,version:DocumentVersion,delivery:PlanningDelivery} */
    private function deliveredScene(array $planLimits = [], string $prefix = 'documents/client-correction'): array
    {
        $scene = $this->renderedPlanningScene($prefix, $planLimits);
        $delivery = app(PublishPlanningDelivery::class)->execute(
            $scene['request'],
            null,
            'd1111111-1111-4111-8111-111111111111',
        );

        return [
            'request' => $scene['request']->fresh(),
            'version' => $scene['version']->fresh(),
            'delivery' => $delivery->fresh('files'),
        ];
    }

    private function requestCorrection(array $scene, array $sections = ['sessions']): CorrectionRequest
    {
        return app(RequestClientCorrection::class)->execute(
            $scene['request']->owner,
            $scene['request']->fresh(),
            'activities',
            'Necesito instrucciones más claras y una actividad de cierre más concreta.',
            $sections,
            'd2222222-2222-4222-8222-222222222222',
        );
    }

    /** @return array{correction:CorrectionRequest,execution:AiExecution} */
    private function startCorrection(array $scene, ?CorrectionRequest $correction = null): array
    {
        $correction ??= $this->requestCorrection($scene);
        app(StartClientCorrection::class)->execute(
            $correction,
            $this->admin(),
            'd3333333-3333-4333-8333-333333333333',
        );
        $execution = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->where('input_manifest->source_kind', 'client')
            ->where('input_manifest->source_correction_request_id', $correction->id)
            ->sole();

        return ['correction' => $correction->fresh('reservation'), 'execution' => $execution];
    }

    /** @return array<string,mixed> */
    private function sessionsCorrection(DocumentVersion $source, string $instruction = 'Instrucción ajustada según la solicitud del docente.'): array
    {
        $sessions = $source->content['sessions'];
        $sessions[0]['moments'][0]['activities'][0]['instruction'] = $instruction;

        return [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $source->id,
            'patch' => ['sessions' => $sessions],
        ];
    }

    public function test_docente_solicita_correccion_sobre_ultima_entrega_sin_consumir_ronda_todavia(): void
    {
        $scene = $this->deliveredScene();
        $correction = $this->requestCorrection($scene);

        $this->assertSame(CorrectionRequestStatus::Requested, $correction->status);
        $this->assertSame($scene['delivery']->version_id, $correction->delivered_version_id);
        $this->assertSame($scene['version']->id, $correction->source_version_id);
        $this->assertSame(['sessions'], $correction->section_keys);
        $this->assertSame(PlanningRequestStatus::CORRECCION_SOLICITADA, $scene['request']->fresh()->status);
        $this->assertSame(0, UsageReservation::query()->where('planning_request_id', $scene['request']->id)->where('resource', UsageResource::ClientCorrection->value)->count());
        $this->assertDatabaseHas('deliveries', ['id' => $scene['delivery']->id, 'version_id' => $scene['version']->id]);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $scene['request']->id,
            'from_status' => 'ENTREGADA',
            'to_status' => 'CORRECCION_SOLICITADA',
            'reason' => 'client_correction_requested',
        ]);
    }

    public function test_revision_cliente_aparece_en_bandeja_admin_y_encola_aviso_operativo(): void
    {
        $admin = $this->admin();
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-admin-inbox');
        $correction = $this->requestCorrection($scene);

        $event = OperationalNotificationEvent::query()
            ->where('type', OperationalNotificationType::ClientCorrectionRequested->value)
            ->where('recipient_id', $admin->id)
            ->where('aggregate_type', 'correction_request')
            ->where('aggregate_id', $correction->id)
            ->sole();

        $this->assertSame('/admin/client-corrections', $event->payload['url']);

        $this->actingAs($admin)
            ->get(ClientCorrections::getUrl(panel: 'admin'))
            ->assertOk()
            ->assertSee('Revisiones de clientes')
            ->assertSee('Esperando tu decisión')
            ->assertSee('Necesito instrucciones más claras')
            ->assertSee('Revisar y decidir');
    }

    public function test_reintento_identico_es_idempotente_y_segunda_solicitud_distinta_conflicta(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-idempotent');
        $first = $this->requestCorrection($scene);
        $same = $this->requestCorrection($scene);
        $this->assertSame($first->id, $same->id);

        try {
            app(RequestClientCorrection::class)->execute(
                $scene['request']->owner,
                $scene['request']->fresh(),
                'assessment',
                'Quiero ajustar únicamente el instrumento de evaluación final.',
                ['assessment_plan'],
            );
            $this->fail('No debe abrirse una segunda corrección mientras exista otra abierta.');
        } catch (ClientCorrectionException $e) {
            $this->assertSame('CLIENT_CORRECTION_ALREADY_OPEN', $e->errorCode);
        }

        $this->assertSame(1, CorrectionRequest::query()->where('request_id', $scene['request']->id)->count());
    }

    public function test_plan_sin_rondas_incluidas_impide_solicitar_correccion(): void
    {
        $scene = $this->deliveredScene(['correction_limit' => 0], 'documents/client-correction-no-quota');

        try {
            $this->requestCorrection($scene);
            $this->fail('Un plan sin rondas incluidas no debe aceptar correcciones del cliente.');
        } catch (ClientCorrectionException $e) {
            $this->assertSame('CLIENT_CORRECTION_NOT_INCLUDED', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::ENTREGADA, $scene['request']->fresh()->status);
    }

    public function test_ventana_vencida_impide_solicitar_correccion(): void
    {
        $scene = $this->deliveredScene(['correction_window_days' => 1], 'documents/client-correction-expired');
        $this->travel(2)->days();

        try {
            $this->requestCorrection($scene);
            $this->fail('La corrección debe respetar la ventana contractual congelada.');
        } catch (ClientCorrectionException $e) {
            $this->assertSame('CLIENT_CORRECTION_WINDOW_EXPIRED', $e->errorCode);
        }

        $this->assertSame(PlanningRequestStatus::ENTREGADA, $scene['request']->fresh()->status);
        $this->assertSame(0, CorrectionRequest::query()->where('request_id', $scene['request']->id)->count());
    }

    public function test_docente_ajeno_no_puede_solicitar_correccion(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-owner');

        $this->expectException(AuthorizationException::class);
        app(RequestClientCorrection::class)->execute(
            $this->customer(),
            $scene['request'],
            'activities',
            'Intento de corrección de una planeación que pertenece a otro usuario.',
            ['sessions'],
        );
    }

    public function test_docente_puede_retirar_antes_de_procesar_y_no_consume_ronda(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-withdraw');
        $correction = $this->requestCorrection($scene);

        app(WithdrawClientCorrection::class)->execute($correction, $scene['request']->owner);

        $this->assertSame(PlanningRequestStatus::ENTREGADA, $scene['request']->fresh()->status);
        $this->assertSame(CorrectionRequestStatus::Withdrawn, $correction->fresh()->status);
        $this->assertSame('withdrawn_by_customer', $correction->fresh()->resolution);
        $this->assertSame(0, UsageReservation::query()->where('planning_request_id', $scene['request']->id)->where('resource', UsageResource::ClientCorrection->value)->count());
    }

    public function test_admin_puede_rechazar_sin_consumir_ronda_y_retorna_a_entregada(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-reject');
        $correction = $this->requestCorrection($scene);

        app(RejectClientCorrection::class)->execute(
            $correction,
            $this->admin(),
            'El cambio solicitado alteraría el alcance curricular congelado.',
        );

        $this->assertSame(PlanningRequestStatus::ENTREGADA, $scene['request']->fresh()->status);
        $this->assertSame(CorrectionRequestStatus::Rejected, $correction->fresh()->status);
        $this->assertSame(0, UsageReservation::query()->where('planning_request_id', $scene['request']->id)->where('resource', UsageResource::ClientCorrection->value)->count());
    }

    public function test_admin_inicia_correccion_reserva_una_ronda_y_crea_ejecucion_client(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-start');
        $started = $this->startCorrection($scene);
        $correction = $started['correction'];
        $execution = $started['execution'];

        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $scene['request']->fresh()->status);
        $this->assertSame(CorrectionRequestStatus::Processing, $correction->status);
        $this->assertSame(UsageReservationStatus::Consumed, $correction->reservation->status);
        $this->assertSame(UsageResource::ClientCorrection, $correction->reservation->resource);
        $this->assertSame(1, $correction->reservation->quantity);
        $this->assertSame('client', $execution->input_manifest['source_kind']);
        $this->assertSame($correction->id, $execution->input_manifest['source_correction_request_id']);
        $this->assertSame(1, $execution->input_manifest['correction_round']);
        $this->assertSame(['sessions'], $execution->input_manifest['section_keys']);
        $this->assertDatabaseHas('outbox_events', [
            'event_key' => 'ai-execution:' . $execution->id . ':correction-dispatch',
            'type' => OutboxEventType::PlanningCorrectionRequested->value,
            'aggregate_id' => $scene['request']->id,
        ]);
    }

    public function test_correccion_solicitada_dentro_de_ventana_se_procesa_aunque_periodo_ya_vencio(): void
    {
        $scene = $this->deliveredScene([
            'correction_window_days' => 60,
            'correction_limit' => 2,
        ], 'documents/client-correction-expired-period');
        $correction = $this->requestCorrection($scene);
        $this->travel(31)->days();

        $started = $this->startCorrection($scene, $correction);

        $this->assertSame(CorrectionRequestStatus::Processing, $started['correction']->status);
        $this->assertSame(UsageReservationStatus::Consumed, $started['correction']->reservation->status);
        $this->assertSame($scene['request']->subscription_period_id, $started['correction']->reservation->subscription_period_id);
    }

    public function test_builder_materializa_solicitud_cliente_como_hallazgos_limitados_al_scope(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-builder');
        $started = $this->startCorrection($scene);

        $input = app(CorrectionInputBuilder::class)->rebuildForExecution($started['execution']->fresh(['request', 'promptVersion']));

        $this->assertSame('client', $input->sourceKind);
        $this->assertSame($started['correction']->id, $input->sourceCorrectionRequestId);
        $this->assertSame(['sessions'], $input->sectionKeys);
        $this->assertCount(1, $input->findings);
        $this->assertSame('PEDAGOGICAL_ALIGNMENT', $input->findings[0]->code);
        $this->assertSame('/sessions', $input->findings[0]->jsonPath);
        $this->assertStringContainsString('instrucciones más claras', $input->findings[0]->explanation);
    }

    public function test_vista_docente_muestra_accion_y_derechos_de_correccion_despues_de_entrega(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-ui');

        $this->actingAs($scene['request']->owner)
            ->get('/app/planning-requests/' . $scene['request']->id)
            ->assertOk()
            ->assertSee('Solicitar corrección')
            ->assertSee('Rondas de corrección disponibles')
            ->assertSee('Solicita correcciones hasta')
            ->assertSee('Planeación lista')
            ->assertSee('Descargar DOCX')
            ->assertSee('Descargar PDF');
    }

    public function test_importar_resultado_cliente_crea_version_hija_resuelve_solicitud_y_obliga_reauditoria(): void
    {
        $scene = $this->deliveredScene(prefix: 'documents/client-correction-import');
        $started = $this->startCorrection($scene);
        $execution = $started['execution'];

        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $execution->id . ':correction-dispatch')->sole(),
        );
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);

        $version = app(ImportManualCorrectionResult::class)->execute(
            $execution->fresh(),
            $this->sessionsCorrection($scene['version']),
        );

        $correction = $started['correction']->fresh();
        $this->assertSame($scene['version']->id, $version->parent_version_id);
        $this->assertSame(CorrectionRequestStatus::Resolved, $correction->status);
        $this->assertSame($version->id, $correction->resulting_version_id);
        $this->assertSame('correction_applied_reaudit_pending', $correction->resolution);
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $scene['request']->fresh()->status);
        $this->assertSame(1, PlanningDelivery::query()->where('request_id', $scene['request']->id)->count(), 'La entrega anterior no se sobrescribe.');

        $audit = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $version->id)
            ->sole();
        $this->assertSame(AiExecutionStatus::Pending, $audit->status);
    }
}
