<?php

namespace Tests\Feature\Review;

use App\Actions\Review\AssignReviewer;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Enums\ApprovalKind;
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

class HumanReviewIntegrityTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    /** Real commits are required so deferred PostgreSQL constraints fire inside each assertion. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;

        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_bd_rechaza_aprobacion_humana_sin_revision_aprobada(): void
    {
        [$review, $reviewer] = $this->startedReview();

        $this->assertDeferredFailure('HUMAN_APPROVAL_REVIEW_MISMATCH', function () use ($review, $reviewer): void {
            DB::table('approvals')->insert([
                'request_id' => $review->request_id,
                'version_id' => $review->version_id,
                'kind' => ApprovalKind::Human->value,
                'ai_execution_id' => null,
                'review_id' => $review->id,
                'actor_id' => $reviewer->id,
                'approved_at' => now(),
                'created_at' => now(),
            ]);
        });
    }

    public function test_bd_rechaza_actor_de_aprobacion_distinto_del_revisor(): void
    {
        [$review] = $this->startedReview();
        $admin = $this->admin();
        $this->passAll($review, $review->reviewer);

        $this->assertDeferredFailure('HUMAN_APPROVAL_REVIEW_MISMATCH', function () use ($review, $admin): void {
            DB::table('reviews')->where('id', $review->id)->update([
                'status' => 'approved', 'decided_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('review_assignments')->where('id', $review->assignment_id)->update([
                'status' => 'completed', 'ended_at' => now(), 'ended_by' => $review->reviewer_id,
                'ended_reason' => 'human_review_approved', 'updated_at' => now(),
            ]);
            DB::table('planning_requests')->where('id', $review->request_id)->update([
                'status' => 'APROBADA', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);
            DB::table('request_state_events')->insert([
                'request_id' => $review->request_id,
                'from_status' => 'REVISION_HUMANA',
                'to_status' => 'APROBADA',
                'actor_id' => $review->reviewer_id,
                'actor_type' => 'user',
                'reason' => 'human_review_approved',
                'correlation_id' => '52525252-5252-4252-8252-525252525252',
                'created_at' => now(),
            ]);
            DB::table('approvals')->insert([
                'request_id' => $review->request_id,
                'version_id' => $review->version_id,
                'kind' => 'human', 'ai_execution_id' => null, 'review_id' => $review->id,
                'actor_id' => $admin->id, 'approved_at' => now(), 'created_at' => now(),
            ]);
        });
    }

    public function test_bd_congela_identidad_de_revision(): void
    {
        [$review] = $this->startedReview();

        try {
            DB::table('reviews')->where('id', $review->id)->update(['version_id' => $review->version_id + 999]);
            $this->fail('Se esperaba HUMAN_REVIEW_IDENTITY_IMMUTABLE');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('HUMAN_REVIEW_IDENTITY_IMMUTABLE', $e->getMessage());
        }
    }

    public function test_bd_exige_evento_de_estado_para_aprobacion_humana(): void
    {
        [$review, $reviewer] = $this->startedReview();
        $this->passAll($review, $reviewer);

        $this->assertDeferredFailure('HUMAN_APPROVAL_STATE_EVENT_REQUIRED', function () use ($review, $reviewer): void {
            DB::table('reviews')->where('id', $review->id)->update([
                'status' => 'approved', 'decided_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('review_assignments')->where('id', $review->assignment_id)->update([
                'status' => 'completed', 'ended_at' => now(), 'ended_by' => $reviewer->id,
                'ended_reason' => 'human_review_approved', 'updated_at' => now(),
            ]);
            DB::table('approvals')->insert([
                'request_id' => $review->request_id, 'version_id' => $review->version_id,
                'kind' => 'human', 'ai_execution_id' => null, 'review_id' => $review->id,
                'actor_id' => $reviewer->id, 'approved_at' => now(), 'created_at' => now(),
            ]);
            DB::table('planning_requests')->where('id', $review->request_id)->update([
                'status' => 'APROBADA', 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now(),
            ]);
        });
    }

    public function test_respuestas_quedan_bloqueadas_al_terminar_revision(): void
    {
        [$review, $reviewer] = $this->startedReview();
        $this->passAll($review, $reviewer);
        app(\App\Actions\Review\ApproveHumanReview::class)->execute($review->fresh(), $reviewer);
        $responseId = DB::table('review_checklist_responses')->where('review_id', $review->id)->value('id');

        try {
            DB::table('review_checklist_responses')->where('id', $responseId)->update(['passed' => false, 'updated_at' => now()]);
            $this->fail('Se esperaba HUMAN_REVIEW_RESPONSES_LOCKED');
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString('HUMAN_REVIEW_RESPONSES_LOCKED', $e->getMessage());
        }
    }

    /** @return array{0:HumanReview,1:User} */
    private function startedReview(): array
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $reviewer = User::query()->findOrFail($profile->user_id);
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);

        return [$review, $reviewer];
    }

    private function passAll(HumanReview $review, User $reviewer): HumanReview
    {
        $responses = [];
        foreach ($review->checklistVersion->items as $item) {
            $responses[$item->key] = ['passed' => true, 'comment' => null];
        }
        return app(SaveHumanReview::class)->execute($review, $reviewer, $responses);
    }

    private function assertDeferredFailure(string $needle, callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Se esperaba error diferido con '.$needle);
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
