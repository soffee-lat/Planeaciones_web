<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\PublishPlanningDelivery;
use App\Enums\PlanningRequestStatus;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesRenderedPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PrivateDeliveryIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesRenderedPlanningScenario;

    /** Real commits are required so deferred PostgreSQL constraints fire. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
    }

    public function test_bd_rechaza_entregada_sin_delivery(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-integrity-missing');

        $this->assertDeferredFailure('DELIVERY_REQUIRED', function () use ($scene): void {
            DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                'status' => PlanningRequestStatus::ENTREGADA->value,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_bd_rechaza_entrega_sin_evento_de_estado(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-integrity-event');
        $files = $scene['version']->outputFiles()->with('file')->get();

        $this->assertDeferredFailure('DELIVERY_STATE_EVENT_REQUIRED', function () use ($scene, $files): void {
            $deliveryId = DB::table('deliveries')->insertGetId([
                'request_id' => $scene['request']->id,
                'version_id' => $scene['version']->id,
                'render_run_id' => $scene['run']->id,
                'delivered_at' => now(),
                'created_by' => null,
                'idempotency_key' => 'forged-delivery-event-' . $scene['request']->id,
                'correlation_id' => 'b2222222-2222-4222-8222-222222222222',
                'created_at' => now(),
            ]);
            foreach ($files as $link) {
                DB::table('files')->where('id', $link->file_id)->update(['retention_until' => now()->addDays(365)]);
                DB::table('delivery_files')->insert([
                    'delivery_id' => $deliveryId,
                    'file_id' => $link->file_id,
                    'output_format' => $link->output_format->value,
                    'renderer_version' => $scene['run']->renderer_version,
                    'created_at' => now(),
                ]);
            }
            DB::table('planning_requests')->where('id', $scene['request']->id)->update([
                'status' => PlanningRequestStatus::ENTREGADA->value,
                'lock_version' => DB::raw('lock_version + 1'),
                'updated_at' => now(),
            ]);
        });
    }

    public function test_bd_congela_entrega_y_sus_archivos(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-integrity-freeze');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $file = $delivery->files->first();

        try {
            DB::table('deliveries')->where('id', $delivery->id)->update(['delivered_at' => now()->addMinute()]);
            $this->fail('Se esperaba DELIVERY_HISTORY_IMMUTABLE.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('DELIVERY_HISTORY_IMMUTABLE', $e->getMessage());
        }

        try {
            DB::table('delivery_files')->where('delivery_id', $delivery->id)->where('file_id', $file->id)->delete();
            $this->fail('Se esperaba DELIVERY_FILE_IMMUTABLE.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('DELIVERY_FILE_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_bd_impide_acortar_retencion_de_archivo_entregado(): void
    {
        $scene = $this->renderedPlanningScene('documents/delivery-integrity-retention');
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        $file = $delivery->files->first();

        try {
            DB::table('files')->where('id', $file->id)->update(['retention_until' => now()->addDay()]);
            $this->fail('Se esperaba FILE_RETENTION_CANNOT_SHORTEN.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('FILE_RETENTION_CANNOT_SHORTEN', $e->getMessage());
        }
    }

    private function assertDeferredFailure(string $needle, callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Se esperaba error diferido con ' . $needle);
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
