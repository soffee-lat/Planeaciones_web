<?php

namespace Tests\Feature\Review;

use App\Actions\Commerce\ConsumePlanningReservation;
use App\Actions\Review\AssignReviewer;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\UsageResource;
use App\Models\ReviewerAssignment;
use App\Models\ReviewerAvailability;
use App\Models\ReviewerProfile;
use App\Models\UsageReservation;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PDOException;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class ReviewerAssignmentIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    /** Real commits are required so deferred PostgreSQL triggers fire inside each assertion. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_bd_rechaza_asignacion_sin_consumo_humano(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_HUMAN_CONSUMPTION_REQUIRED', function () use ($scene, $profile): void {
            DB::table('review_assignments')->insert($this->payload($scene['request']->id, $profile->user_id, 4, 2500, 10000));
        });
    }

    public function test_bd_rechaza_grado_no_autorizado(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = ReviewerProfile::factory()->create(['user_id' => $this->reviewer()->id]);
        ReviewerAvailability::factory()->create([
            'reviewer_id' => $profile->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);
        $this->consumeHuman($scene['request']->id);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_GRADE_NOT_AUTHORIZED', function () use ($scene, $profile): void {
            DB::table('review_assignments')->insert($this->payload($scene['request']->id, $profile->user_id, 4, 2500, 10000));
        });
    }

    public function test_bd_rechaza_snapshot_de_tarifa_fabricado(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['rate_minor' => 2500]);
        $this->consumeHuman($scene['request']->id);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_RATE_SNAPSHOT_MISMATCH', function () use ($scene, $profile): void {
            DB::table('review_assignments')->insert($this->payload($scene['request']->id, $profile->user_id, 4, 999, 3996));
        });
    }

    public function test_bd_rechaza_sobrecarga_max_y_daily(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['max_load' => 3, 'daily_max' => 8]);
        $this->consumeHuman($scene['request']->id);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_MAX_LOAD_EXCEEDED', function () use ($scene, $profile): void {
            DB::table('review_assignments')->insert($this->payload($scene['request']->id, $profile->user_id, 4, 2500, 10000));
        });

        // El insert previo se revirtió. Cambiamos la capacidad del perfil y
        // probamos de forma independiente el máximo diario.
        $profile->forceFill(['max_load' => 8, 'daily_max' => 3])->save();
        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_DAILY_MAX_EXCEEDED', function () use ($scene, $profile): void {
            DB::table('review_assignments')->insert($this->payload($scene['request']->id, $profile->user_id, 4, 2500, 10000));
        });
    }

    public function test_bd_congela_identidad_e_historial_de_asignacion(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $first = $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']);
        $second = $this->reviewerForRequest($scene['request']);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_IDENTITY_IMMUTABLE', function () use ($assignment, $second): void {
            DB::table('review_assignments')->where('id', $assignment?->id)->update(['reviewer_id' => $second->user_id]);
        });
        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_HISTORY_IMMUTABLE', function () use ($assignment): void {
            DB::table('review_assignments')->where('id', $assignment?->id)->delete();
        });
        $this->assertSame($first->user_id, $assignment?->fresh()->reviewer_id);
    }

    public function test_bd_no_permite_revocar_grado_o_disponibilidad_de_asignacion_activa(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);
        app(AssignReviewer::class)->execute($scene['request']);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_GRADE_NOT_AUTHORIZED', function () use ($profile, $scene): void {
            DB::table('reviewer_grades')
                ->where('reviewer_id', $profile->user_id)
                ->where('grade_id', $scene['request']->grade_id)
                ->delete();
        });
        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_AVAILABILITY_REQUIRED', function () use ($profile): void {
            DB::table('reviewer_availability')->where('reviewer_id', $profile->user_id)->delete();
        });
    }

    public function test_bd_no_permite_desactivar_perfil_con_asignacion_activa_pero_si_cambiar_tarifa_futura(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['rate_minor' => 1000]);
        $assignment = app(AssignReviewer::class)->execute($scene['request']);

        $this->assertQueryExceptionContains('REVIEW_ASSIGNMENT_REVIEWER_INACTIVE', function () use ($profile): void {
            DB::table('reviewer_profiles')->where('id', $profile->id)->update(['status' => 'inactive']);
        });

        DB::table('reviewer_profiles')->where('id', $profile->id)->update(['rate_minor' => 1500]);
        $this->assertSame(1000, $assignment?->fresh()->rate_snapshot_minor);
        $this->assertSame(1500, (int) $profile->fresh()->rate_minor);
    }

    public function test_indice_impide_dos_asignaciones_activas_para_misma_solicitud(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $first = $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']);
        $second = $this->reviewerForRequest($scene['request']);

        try {
            DB::transaction(fn () => DB::table('review_assignments')->insert($this->payload(
                $scene['request']->id,
                $second->user_id,
                4,
                (int) $second->rate_minor,
                (int) $second->rate_minor * 4,
            )));
            $this->fail('Debe impedir una segunda asignación activa.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('review_assignments_one_active_request_uniq', $e->getMessage());
        }

        $this->assertSame(1, ReviewerAssignment::query()
            ->where('request_id', $scene['request']->id)
            ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
            ->count());
        $this->assertSame($first->user_id, $assignment?->reviewer_id);
    }

    /** @return array<string,mixed> */
    private function payload(int $requestId, int $reviewerId, int $units, int $rate, int $total): array
    {
        return [
            'request_id' => $requestId,
            'reviewer_id' => $reviewerId,
            'cycle' => 1,
            'status' => 'assigned',
            'due_at' => now()->addDays(2),
            'assigned_at' => now(),
            'started_at' => null,
            'ended_at' => null,
            'ended_by' => null,
            'ended_reason' => null,
            'rate_snapshot_minor' => $rate,
            'units_snapshot' => $units,
            'total_fee_minor' => $total,
            'currency' => 'MXN',
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    private function consumeHuman(int $requestId): void
    {
        $reservation = UsageReservation::query()
            ->where('planning_request_id', $requestId)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
        app(ConsumePlanningReservation::class)($reservation);
    }

    private function assertQueryExceptionContains(string $needle, callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Se esperaba QueryException con '.$needle);
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
