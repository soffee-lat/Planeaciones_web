<?php

namespace Tests\Feature\AI;

use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\OutboxEventType;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\AiManualPackage;
use App\Models\OutboxEvent;
use App\Models\RequestBlock;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class ManualCorrectionPipelineTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;

    /** @return array{0:AiExecution,1:OutboxEvent} */
    private function pendingCorrection(): array
    {
        $scene = $this->succeededAuditScenario(false);
        app(RouteAuditResult::class)->execute(
            $scene['audit'],
            null,
            '61616161-6161-4616-8616-616161616161',
        );
        $correction = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        $event = OutboxEvent::query()
            ->where('aggregate_id', $scene['request']->id)
            ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
            ->sole();

        return [$correction, $event];
    }

    public function test_procesar_outbox_correction_crea_paquete_privado_exacto(): void
    {
        [$correction, $event] = $this->pendingCorrection();

        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event));

        $package = AiManualPackage::query()->where('ai_execution_id', $correction->id)->sole();
        $this->assertSame(AiExecutionStatus::WaitingManual, $correction->fresh()->status);
        $this->assertNotNull($event->fresh()->published_at);
        $this->assertTrue(Storage::disk($package->disk)->exists($package->path));
        $payload = json_decode(Storage::disk($package->disk)->get($package->path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('manual_correction', $payload['kind']);
        $this->assertSame($correction->id, $payload['execution']['id']);
        $this->assertSame($correction->input_manifest['source_version_id'], $payload['source']['document_version_id']);
        $this->assertSame($correction->input_manifest['source_audit_execution_id'], $payload['source']['audit_execution_id']);
        $this->assertSame(['sessions'], $payload['source']['section_keys']);
        $this->assertSame('correction_result_v1', $payload['output']['schema_version']);
    }

    public function test_evento_correction_publicado_no_se_procesa_dos_veces(): void
    {
        [$correction, $event] = $this->pendingCorrection();

        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event));
        $this->assertFalse(app(ProcessOutboxEvent::class)->execute($event->fresh()));
        $this->assertSame(1, AiManualPackage::query()->where('ai_execution_id', $correction->id)->count());
    }

    public function test_fallo_preparando_paquete_correction_abre_bloqueo_y_deja_evento_reintentable(): void
    {
        [$correction, $event] = $this->pendingCorrection();
        config(['ai.manual.disk' => 'missing-private-disk']);

        try {
            app(ProcessOutboxEvent::class)->execute($event);
            $this->fail('Debe fallar sin disco privado válido.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $e->errorCode);
        }

        $this->assertNull($event->fresh()->published_at);
        $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $correction->fresh()->error_code);
        $block = RequestBlock::query()
            ->where('request_id', $correction->request_id)
            ->where('code', 'ai_failed')
            ->where('stage', AiExecutionStage::Correction->value)
            ->whereNull('resolved_at')
            ->sole();
        $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $block->details['error_code']);
    }
}
