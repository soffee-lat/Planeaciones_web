<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\AiExecutionStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\AiPipelineException;
use App\Models\AiManualPackage;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\RequestBlock;
use App\Models\UsageReservation;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class ManualGenerationOutboxTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    private function readyRequest(): PlanningRequest
    {
        $request = $this->draft();
        $this->period($request);
        return $this->authorize($this->confirm($request));
    }

    private function publishPrompt(array $overrides = []): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.generation',
            'category' => PromptCategory::Generation->value,
        ]);
        $version = PromptVersion::factory()->create(array_merge([
            'template_id' => $template->id,
            'body' => "Genera JSON. Entrada: {{input_snapshot}}\nSchema: {{output_schema}}",
            'allowed_variables' => ['input_snapshot', 'output_schema'],
        ], $overrides));
        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }

    private function dispatched(): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/manual-test',
            'ai.outbox.retry_seconds' => 1,
        ]);
        Storage::fake('private');
        $request = $this->readyRequest();
        $this->publishPrompt();
        $execution = app(DispatchPlanningGeneration::class)->execute($request, '44444444-4444-4444-8444-444444444444');
        return [$request->fresh(), $execution->fresh(), OutboxEvent::query()->sole()];
    }

    public function test_procesar_outbox_crea_paquete_privado_exactamente_una_vez(): void
    {
        [$request, $execution, $event] = $this->dispatched();

        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event));

        $execution = $execution->fresh(['manualPackage']);
        $event = $event->fresh();
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->status);
        $this->assertNotNull($execution->rendered_prompt_hash);
        $this->assertNotNull($event->published_at);
        $this->assertSame(1, $event->attempts);
        $this->assertNull($event->last_error_code);
        $this->assertNotNull($execution->manualPackage);

        $package = $execution->manualPackage;
        Storage::disk($package->disk)->assertExists($package->path);
        $bytes = Storage::disk($package->disk)->get($package->path);
        $this->assertSame($package->checksum, hash('sha256', $bytes));
        $this->assertSame($package->size_bytes, strlen($bytes));

        $decoded = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('manual_generation', $decoded['kind']);
        $this->assertSame($request->id, $decoded['request']['id']);
        $this->assertSame($execution->id, $decoded['execution']['id']);
        $this->assertSame($execution->rendered_prompt_hash, $decoded['prompt']['rendered_sha256']);
        $this->assertTrue($decoded['output']['return_json_only']);
        $this->assertNull($execution->provider);
        $this->assertNull($execution->model);
    }

    public function test_evento_publicado_no_se_procesa_dos_veces(): void
    {
        [, $execution, $event] = $this->dispatched();
        $processor = app(ProcessOutboxEvent::class);

        $this->assertTrue($processor->execute($event));
        $this->assertFalse($processor->execute($event->fresh()));
        $this->assertSame(1, AiManualPackage::query()->where('ai_execution_id', $execution->id)->count());
        $this->assertSame(1, $event->fresh()->attempts);
    }

    public function test_lease_vigente_impide_reclamo_y_lease_vencida_se_recupera(): void
    {
        [, , $event] = $this->dispatched();
        $event->forceFill([
            'claimed_at' => now(),
            'lease_expires_at' => now()->addMinute(),
        ])->save();
        $this->assertFalse(app(ProcessOutboxEvent::class)->execute($event->fresh()));

        $event->forceFill([
            'claimed_at' => now()->subMinutes(2),
            'lease_expires_at' => now()->subMinute(),
        ])->save();
        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event->fresh()));
        $this->assertNotNull($event->fresh()->published_at);
    }

    public function test_error_transitorio_abre_bloqueo_y_reintento_lo_resuelve_sin_doble_consumo(): void
    {
        [$request, $execution, $event] = $this->dispatched();
        config(['ai.manual.disk' => 'disk-that-does-not-exist']);

        try {
            app(ProcessOutboxEvent::class)->execute($event);
            $this->fail('Debió fallar el almacenamiento privado no configurado.');
        } catch (\Throwable) {
            // La excepción concreta del filesystem no se persiste ni se expone.
        }

        $request = $request->fresh();
        $this->assertSame(PlanningRequestStatus::GENERACION_IA, $request->status);
        $planning = UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->sole();
        $this->assertSame(UsageReservationStatus::Consumed, $planning->status);
        $consumedAt = $planning->consumed_at;

        $event = $event->fresh();
        $this->assertNull($event->published_at);
        $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $event->last_error_code);
        $this->assertNull($event->claimed_at);
        $this->assertNull($event->lease_expires_at);
        $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $execution->fresh()->error_code);

        $block = RequestBlock::query()->where('request_id', $request->id)->whereNull('resolved_at')->sole();
        $this->assertSame('ai_failed', $block->code);
        $this->assertSame('generation', $block->stage);
        $this->assertCount(2, $block->details);
        $this->assertSame('AI_MANUAL_PRIVATE_DISK_INVALID', $block->details['error_code']);
        $this->assertSame(1, $block->details['attempts']);

        config(['ai.manual.disk' => 'private']);
        $event->forceFill(['available_at' => now()->subSecond()])->save();
        $this->assertTrue(app(ProcessOutboxEvent::class)->execute($event->fresh()));

        $this->assertNotNull($event->fresh()->published_at);
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);
        $this->assertNotNull($block->fresh()->resolved_at);
        $this->assertEquals($consumedAt, $planning->fresh()->consumed_at);
        $this->assertSame(1, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::Planning->value)
            ->count());
    }

    public function test_bloqueo_tecnico_preexistente_se_resuelve_al_publicar_evento(): void
    {
        [$request, , $event] = $this->dispatched();
        RequestBlock::query()->create([
            'request_id' => $request->id,
            'code' => 'ai_failed',
            'stage' => 'generation',
            'details' => ['error_code' => 'TRANSIENT'],
            'opened_at' => now(),
        ]);

        app(ProcessOutboxEvent::class)->execute($event);

        $block = RequestBlock::query()->where('request_id', $request->id)->sole();
        $this->assertNotNull($block->resolved_at);
    }

    public function test_comando_recupera_eventos_pendientes(): void
    {
        [, $execution] = $this->dispatched();

        $exit = Artisan::call('ai:process-outbox', ['--limit' => 10]);

        $this->assertSame(0, $exit);
        $this->assertSame(AiExecutionStatus::WaitingManual, $execution->fresh()->status);
        $this->assertNotNull(OutboxEvent::query()->sole()->published_at);
    }

    public function test_bd_impide_borrar_evento_outbox_publicado_o_paquete_manual(): void
    {
        [, $execution, $event] = $this->dispatched();
        app(ProcessOutboxEvent::class)->execute($event);
        $package = $execution->fresh()->manualPackage;

        try {
            DB::transaction(fn () => DB::table('outbox_events')->where('id', $event->id)->delete());
            $this->fail('Outbox history debía ser inmutable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('OUTBOX_EVENT_HISTORY_IMMUTABLE', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('AI_MANUAL_PACKAGE_IMMUTABLE');
        DB::transaction(fn () => DB::table('ai_manual_packages')->where('id', $package->id)->delete());
    }

    public function test_bd_protege_evento_de_estado_y_outbox_publicado_contra_mutacion_directa(): void
    {
        [$request, , $event] = $this->dispatched();
        app(ProcessOutboxEvent::class)->execute($event);
        $stateEvent = $request->stateEvents()->where('to_status', 'GENERACION_IA')->sole();

        try {
            DB::transaction(fn () => DB::table('request_state_events')
                ->where('id', $stateEvent->id)
                ->update(['reason' => 'tampered']));
            $this->fail('El historial de estados debía ser inmutable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('REQUEST_STATE_EVENT_IMMUTABLE', $e->getMessage());
        }

        try {
            DB::transaction(fn () => DB::table('outbox_events')
                ->where('id', $event->id)
                ->update(['attempts' => 99]));
            $this->fail('Un outbox publicado debía ser inmutable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('OUTBOX_EVENT_PUBLISHED_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_bd_protege_identidad_y_historial_de_bloqueo_tecnico(): void
    {
        [$request] = $this->dispatched();
        $block = RequestBlock::query()->create([
            'request_id' => $request->id,
            'code' => 'ai_failed',
            'stage' => 'generation',
            'details' => ['error_code' => 'TEST'],
            'opened_at' => now(),
        ]);

        try {
            DB::transaction(fn () => DB::table('request_blocks')
                ->where('id', $block->id)
                ->update(['details' => json_encode(['error_code' => 'TAMPERED'], JSON_THROW_ON_ERROR)]));
            $this->fail('La identidad del bloqueo debía ser inmutable.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('REQUEST_BLOCK_IDENTITY_IMMUTABLE', $e->getMessage());
        }

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('REQUEST_BLOCK_HISTORY_IMMUTABLE');
        DB::transaction(fn () => DB::table('request_blocks')->where('id', $block->id)->delete());
    }
}
