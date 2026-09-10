<?php

namespace Tests\Feature\Planning;

use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Planning\RequestClientCorrection;
use App\Actions\Planning\WithdrawClientCorrection;
use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
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

class ClientCorrectionIntegrityTest extends PedagogyTestCase
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

    /** @return array{request:\App\Models\PlanningRequest,version:\App\Models\DocumentVersion,delivery:\App\Models\PlanningDelivery} */
    private function deliveredScene(string $prefix): array
    {
        $scene = $this->renderedPlanningScene($prefix);
        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);

        return [
            'request' => $scene['request']->fresh(),
            'version' => $scene['version']->fresh(),
            'delivery' => $delivery,
        ];
    }

    public function test_bd_rechaza_correccion_cliente_sin_transicion_del_padre(): void
    {
        $scene = $this->deliveredScene('documents/client-correction-integrity-parent');

        $this->assertDeferredFailure('CLIENT_CORRECTION_REQUEST_STATE_REQUIRED', function () use ($scene): void {
            DB::table('correction_requests')->insert([
                'request_id' => $scene['request']->id,
                'delivered_version_id' => $scene['version']->id,
                'source_version_id' => $scene['version']->id,
                'requester_id' => $scene['request']->owner_id,
                'type' => CorrectionRequestType::Client->value,
                'reason' => 'activities',
                'description' => 'Intento directo sin transición del estado de la solicitud.',
                'section_keys' => json_encode(['sessions'], JSON_THROW_ON_ERROR),
                'status' => CorrectionRequestStatus::Requested->value,
                'assigned_to' => null,
                'requested_at' => now(),
                'resolved_at' => null,
                'resolution' => null,
                'resulting_version_id' => null,
                'created_at' => now(),
            ]);
        });
    }

    public function test_bd_impide_dos_correcciones_abiertas_para_la_misma_solicitud(): void
    {
        $scene = $this->deliveredScene('documents/client-correction-integrity-open');
        app(RequestClientCorrection::class)->execute(
            $scene['request']->owner,
            $scene['request'],
            'activities',
            'Primera solicitud válida de corrección para esta planeación.',
            ['sessions'],
        );

        try {
            DB::table('correction_requests')->insert([
                'request_id' => $scene['request']->id,
                'delivered_version_id' => $scene['version']->id,
                'source_version_id' => $scene['version']->id,
                'requester_id' => $scene['request']->owner_id,
                'type' => CorrectionRequestType::Client->value,
                'reason' => 'assessment',
                'description' => 'Segunda solicitud abierta que la base de datos debe rechazar.',
                'section_keys' => json_encode(['assessment_plan'], JSON_THROW_ON_ERROR),
                'status' => CorrectionRequestStatus::Requested->value,
                'assigned_to' => null,
                'requested_at' => now(),
                'resolved_at' => null,
                'resolution' => null,
                'resulting_version_id' => null,
                'created_at' => now(),
            ]);
            $this->fail('Se esperaba el índice parcial de una sola corrección abierta.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('correction_requests_one_open_per_request_idx', $e->getMessage());
        }
    }

    public function test_bd_congela_correccion_terminal(): void
    {
        $scene = $this->deliveredScene('documents/client-correction-integrity-terminal');
        $correction = app(RequestClientCorrection::class)->execute(
            $scene['request']->owner,
            $scene['request'],
            'activities',
            'Solicitud que será retirada para comprobar historial inmutable.',
            ['sessions'],
        );
        app(WithdrawClientCorrection::class)->execute($correction, $scene['request']->owner);

        try {
            DB::table('correction_requests')->where('id', $correction->id)->update([
                'resolution' => 'manipulated_after_terminal_state',
            ]);
            $this->fail('Una corrección terminal no debe poder reescribirse.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('CORRECTION_REQUEST_TERMINAL_IMMUTABLE', $e->getMessage());
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
