<?php

namespace Tests\Feature\Review;

use App\Actions\Review\AssignReviewer;
use App\Actions\Review\RequestHumanReviewCorrection;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Models\HumanReview;
use App\Models\User;
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

class HumanReviewDecisionIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    /** Real commits are required so deferred PostgreSQL constraints fire. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;

        // No hacemos migrate:rollback al destruir la app: una revisión ya
        // decidida puede usar estados propios de 5C (changes_requested,
        // escalated o rejected), incompatibles con el CHECK anterior de 5B.
        // Cada prueba empieza con migrate:fresh, así que el aislamiento se
        // conserva sin intentar degradar el esquema sobre datos 5C vivos.
    }

    public function test_bd_acepta_correccion_humana_completa_con_ejecucion_outbox_y_evento(): void
    {
        [$review, $reviewer] = $this->reviewWithFailedActivity();

        $request = app(RequestHumanReviewCorrection::class)->execute(
            $review,
            $reviewer,
            '71717171-7171-4171-8171-717171717171',
        );

        $this->assertSame('CORRECCION_IA', $request->status->value);
        $this->assertSame('changes_requested', $review->fresh()->status->value);
    }

    public function test_bd_rechaza_changes_requested_fabricado_sin_ejecucion_de_correccion(): void
    {
        [$review, $reviewer] = $this->reviewWithFailedActivity();

        try {
            DB::transaction(function () use ($review, $reviewer): void {
                DB::table('reviews')->where('id', $review->id)->update([
                    'status' => 'changes_requested', 'decided_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('review_assignments')->where('id', $review->assignment_id)->update([
                    'status' => 'completed', 'ended_at' => now(), 'ended_by' => $reviewer->id,
                    'ended_reason' => 'human_review_changes_requested', 'updated_at' => now(),
                ]);
                DB::table('planning_requests')->where('id', $review->request_id)->update([
                    'status' => 'CORRECCION_IA', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
                ]);
                DB::table('request_state_events')->insert([
                    'request_id' => $review->request_id,
                    'from_status' => 'REVISION_HUMANA',
                    'to_status' => 'CORRECCION_IA',
                    'actor_id' => $reviewer->id,
                    'actor_type' => 'user',
                    'reason' => 'human_review_changes_requested',
                    'correlation_id' => '72727272-7272-4272-8272-727272727272',
                    'created_at' => now(),
                ]);
            });
            $this->fail('Se esperaba integridad de ejecución de corrección humana.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('CORRECTION_EXECUTION_REQUIRED', $e->getMessage());
        }
    }

    public function test_bd_rechaza_escalado_fabricado_sin_bloqueo_administrativo(): void
    {
        [$review, $reviewer] = $this->startedReview();

        try {
            DB::transaction(function () use ($review, $reviewer): void {
                DB::table('reviews')->where('id', $review->id)->update([
                    'status' => 'escalated', 'decided_at' => now(), 'general_comment' => 'Escalado ficticio.', 'updated_at' => now(),
                ]);
                DB::table('review_assignments')->where('id', $review->assignment_id)->update([
                    'status' => 'cancelled', 'ended_at' => now(), 'ended_by' => $reviewer->id,
                    'ended_reason' => 'human_review_escalated', 'updated_at' => now(),
                ]);
            });
            $this->fail('Se esperaba HUMAN_REVIEW_ADMIN_BLOCK_REQUIRED.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('HUMAN_REVIEW_ADMIN_BLOCK_REQUIRED', $e->getMessage());
        }
    }

    public function test_bd_congela_review_despues_de_solicitar_cambios(): void
    {
        [$review, $reviewer] = $this->reviewWithFailedActivity();
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);

        try {
            DB::table('reviews')->where('id', $review->id)->update([
                'general_comment' => 'Intento de mutación posterior.',
                'updated_at' => now(),
            ]);
            $this->fail('Se esperaba HUMAN_REVIEW_TERMINAL_IMMUTABLE.');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('HUMAN_REVIEW_TERMINAL_IMMUTABLE', $e->getMessage());
        }
    }

    /** @return array{0:HumanReview,1:User} */
    private function startedReview(): array
    {
        config(['ai.human_review_correction.max_rounds' => 3]);
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $reviewer = User::query()->findOrFail($profile->user_id);
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);

        return [$review, $reviewer];
    }

    /** @return array{0:HumanReview,1:User} */
    private function reviewWithFailedActivity(): array
    {
        [$review, $reviewer] = $this->startedReview();
        $responses = [];
        foreach ($review->checklistVersion->items as $item) {
            $responses[$item->key] = ['passed' => true, 'comment' => null];
        }
        $responses['activities'] = ['passed' => false, 'comment' => 'Revisar actividad.'];
        $review = app(SaveHumanReview::class)->execute($review, $reviewer, $responses, [
            'sessions' => 'Corregir únicamente las actividades de la sesión.',
        ]);

        return [$review, $reviewer];
    }
}
