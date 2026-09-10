<?php

namespace Tests\Feature\Review;

use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Actions\Review\ApproveHumanReview;
use App\Actions\Review\ApproveReviewerSettlement;
use App\Actions\Review\AssignReviewer;
use App\Actions\Review\CreateReviewerSettlement;
use App\Actions\Review\MarkReviewerSettlementPaid;
use App\Actions\Review\ReassignReviewer;
use App\Actions\Review\RequestHumanReviewCorrection;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Enums\AiExecutionStage;
use App\Enums\ReviewerSettlementStatus;
use App\Enums\ReviewerWorkItemStatus;
use App\Exceptions\ReviewerCompensationException;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\ReviewerAvailability;
use App\Models\ReviewerProfile;
use App\Models\ReviewerWorkItem;
use App\Models\User;
use App\Services\Review\ReviewerMetricsService;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class ReviewerCompensationTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    public function test_aprobar_revision_crea_un_solo_trabajo_pagable_con_snapshot_tarifa_por_unidades(): void
    {
        [$review, $assignment, $reviewer] = $this->readyToApproveReview(2750);

        app(ApproveHumanReview::class)->execute($review, $reviewer);

        $item = ReviewerWorkItem::query()->sole();
        $this->assertSame($review->request_id, $item->request_id);
        $this->assertSame($review->id, $item->review_id);
        $this->assertSame($assignment->id, $item->assignment_id);
        $this->assertSame($reviewer->id, $item->reviewer_id);
        $this->assertSame($assignment->cycle, $item->cycle);
        $this->assertSame(2750, $item->rate_minor);
        $this->assertSame($assignment->units_snapshot, $item->quantity);
        $this->assertSame(2750 * $assignment->units_snapshot, $item->total_minor);
        $this->assertSame(ReviewerWorkItemStatus::Approved, $item->status);
    }

    public function test_cambio_de_tarifa_despues_de_asignar_no_reescribe_honorario(): void
    {
        [$review, $assignment, $reviewer, $profile] = $this->readyToApproveReview(1800, true);
        $profile->forceFill(['rate_minor' => 9900])->save();

        app(ApproveHumanReview::class)->execute($review, $reviewer);

        $item = ReviewerWorkItem::query()->sole();
        $this->assertSame(1800, $item->rate_minor);
        $this->assertSame($assignment->total_fee_minor, $item->total_minor);
        $this->assertSame(9900, (int) $profile->fresh()->rate_minor);
    }

    public function test_aprobar_dos_veces_no_duplica_trabajo_pagable(): void
    {
        [$review, , $reviewer] = $this->readyToApproveReview();

        app(ApproveHumanReview::class)->execute($review, $reviewer);
        app(ApproveHumanReview::class)->execute($review->fresh(), $reviewer);

        $this->assertSame(1, ReviewerWorkItem::query()->where('request_id', $review->request_id)->count());
    }

    public function test_reasignacion_antes_de_aprobar_paga_snapshot_de_asignacion_final(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $firstProfile = $this->reviewerForRequest($scene['request'], ['rate_minor' => 1500]);
        $first = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $this->assertNotNull($first);

        $secondProfile = $this->reviewerForRequest($scene['request'], ['rate_minor' => 3200]);
        $replacement = app(ReassignReviewer::class)->execute($this->admin(), $scene['request']->fresh(), 'Balance de carga.');
        $this->assertNotNull($replacement);
        $this->assertSame($secondProfile->user_id, $replacement->reviewer_id);
        $this->assertSame($first->cycle, $replacement->cycle);

        $reviewer = User::query()->findOrFail($replacement->reviewer_id);
        $review = $this->passAll(app(StartHumanReview::class)->execute($replacement, $reviewer), $reviewer);
        app(ApproveHumanReview::class)->execute($review, $reviewer);

        $item = ReviewerWorkItem::query()->sole();
        $this->assertSame($replacement->id, $item->assignment_id);
        $this->assertSame($secondProfile->user_id, $item->reviewer_id);
        $this->assertSame(3200, $item->rate_minor);
        $this->assertNotSame($firstProfile->user_id, $item->reviewer_id);
    }

    public function test_liquidacion_agrupa_trabajos_aprobados_y_pago_marca_todos_una_sola_vez(): void
    {
        [$firstItem, $reviewer] = $this->approvedWorkItemForReviewer(null, 2100);
        $secondItem = $this->approvedWorkItemForExistingReviewer($reviewer, 2100);
        $admin = $this->admin();

        $settlement = app(CreateReviewerSettlement::class)->execute($reviewer, $admin, 'MXN');
        $this->assertCount(2, $settlement->items);
        $this->assertSame($firstItem->total_minor + $secondItem->total_minor, $settlement->totalMinor());

        $settlement = app(ApproveReviewerSettlement::class)->execute($settlement, $admin);
        $this->assertSame(ReviewerSettlementStatus::Approved, $settlement->status);

        $paid = app(MarkReviewerSettlementPaid::class)->execute($settlement, $admin, 'SPEI-2026-0001');
        $this->assertSame(ReviewerSettlementStatus::Paid, $paid->status);
        $this->assertSame('SPEI-2026-0001', $paid->reference);
        $this->assertSame(0, ReviewerWorkItem::query()->where('settlement_id', $paid->id)->where('status', ReviewerWorkItemStatus::Approved->value)->count());
        $this->assertSame(2, ReviewerWorkItem::query()->where('settlement_id', $paid->id)->where('status', ReviewerWorkItemStatus::Paid->value)->count());

        $again = app(MarkReviewerSettlementPaid::class)->execute($paid->fresh(), $admin, 'SPEI-2026-0001');
        $this->assertSame($paid->id, $again->id);
    }

    public function test_correccion_humana_del_mismo_ciclo_no_genera_segundo_honorario(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['rate_minor' => 2400, 'max_load' => 16, 'daily_max' => 16]);
        $reviewer = User::query()->findOrFail($profile->user_id);
        $firstAssignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $review = app(StartHumanReview::class)->execute($firstAssignment, $reviewer);

        $responses = [];
        foreach ($review->checklistVersion->items as $item) {
            $responses[$item->key] = ['passed' => true, 'comment' => null];
        }
        $responses['activities'] = ['passed' => false, 'comment' => 'La actividad necesita ajuste.'];
        $review = app(SaveHumanReview::class)->execute($review, $reviewer, $responses, [
            'sessions' => 'Corregir únicamente la actividad inicial.',
        ]);
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);

        $correction = AiExecution::query()
            ->where('request_id', $review->request_id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:'.$correction->id.':correction-dispatch')->sole(),
        );
        $source = $review->version()->firstOrFail();
        $sessions = $source->content['sessions'];
        $sessions[0]['moments'][0]['activities'][0]['instruction'] = 'Actividad corregida para aprobación humana.';
        $newVersion = app(ImportManualCorrectionResult::class)->execute($correction->fresh(), [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $source->id,
            'patch' => ['sessions' => $sessions],
        ]);

        $audit = AiExecution::query()
            ->where('request_id', $review->request_id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $newVersion->id)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:'.$audit->id.':audit-dispatch')->sole(),
        );
        app(ImportManualAuditResult::class)->execute($audit->fresh(), [
            'schema_version' => 'audit_result_v1',
            'passed' => true,
            'findings' => [],
        ]);
        $request = app(RouteAuditResult::class)->execute($audit->fresh());

        $secondAssignment = $request->reviewAssignments()
            ->whereIn('status', ['assigned', 'in_progress'])
            ->sole();
        $secondReview = $this->passAll(app(StartHumanReview::class)->execute($secondAssignment, $reviewer), $reviewer);
        app(ApproveHumanReview::class)->execute($secondReview, $reviewer);

        $this->assertSame($firstAssignment->cycle, $secondAssignment->cycle);
        $this->assertSame(1, ReviewerWorkItem::query()->where('request_id', $request->id)->count());
        $workItem = ReviewerWorkItem::query()->where('request_id', $request->id)->sole();
        $this->assertSame($secondReview->id, $workItem->review_id);
        $this->assertSame($secondAssignment->id, $workItem->assignment_id);
        $this->assertSame(2400, $workItem->rate_minor);
    }

    public function test_reintento_de_pago_con_otra_referencia_se_rechaza(): void
    {
        [, $reviewer] = $this->approvedWorkItemForReviewer();
        $admin = $this->admin();
        $settlement = app(CreateReviewerSettlement::class)->execute($reviewer, $admin);
        $settlement = app(ApproveReviewerSettlement::class)->execute($settlement, $admin);
        $settlement = app(MarkReviewerSettlementPaid::class)->execute($settlement, $admin, 'REF-A');

        $this->expectException(ReviewerCompensationException::class);
        $this->expectExceptionMessage('REVIEWER_SETTLEMENT_PAYMENT_CONFLICT');
        app(MarkReviewerSettlementPaid::class)->execute($settlement->fresh(), $admin, 'REF-B');
    }

    public function test_solo_administracion_puede_crear_liquidacion(): void
    {
        [, $reviewer] = $this->approvedWorkItemForReviewer();

        $this->expectException(ReviewerCompensationException::class);
        $this->expectExceptionMessage('REVIEWER_SETTLEMENT_ADMIN_REQUIRED');
        app(CreateReviewerSettlement::class)->execute($reviewer, $reviewer);
    }

    public function test_metricas_del_revisor_separan_por_liquidar_y_pagado(): void
    {
        [, $reviewer] = $this->approvedWorkItemForReviewer(null, 2000);
        $metrics = app(ReviewerMetricsService::class)->snapshot($reviewer);
        $this->assertSame(1, $metrics['payable_items']);
        $this->assertSame(0, $metrics['paid_items']);
        $this->assertGreaterThan(0, $metrics['amounts']['MXN']['approved_minor']);

        $admin = $this->admin();
        $settlement = app(CreateReviewerSettlement::class)->execute($reviewer, $admin);
        $settlement = app(ApproveReviewerSettlement::class)->execute($settlement, $admin);
        app(MarkReviewerSettlementPaid::class)->execute($settlement, $admin, 'METRICA-1');

        $after = app(ReviewerMetricsService::class)->snapshot($reviewer);
        $this->assertSame(0, $after['payable_items']);
        $this->assertSame(1, $after['paid_items']);
        $this->assertSame(0, $after['amounts']['MXN']['approved_minor']);
        $this->assertGreaterThan(0, $after['amounts']['MXN']['paid_minor']);
    }

    /** @return array{0:\App\Models\HumanReview,1:\App\Models\ReviewerAssignment,2:User,3?:ReviewerProfile} */
    private function readyToApproveReview(int $rate = 2500, bool $withProfile = false): array
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['rate_minor' => $rate, 'max_load' => 16, 'daily_max' => 16]);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $reviewer = User::query()->findOrFail($profile->user_id);
        $review = $this->passAll(app(StartHumanReview::class)->execute($assignment, $reviewer), $reviewer);

        return $withProfile
            ? [$review, $assignment, $reviewer, $profile]
            : [$review, $assignment, $reviewer];
    }

    /** @return array{0:ReviewerWorkItem,1:User} */
    private function approvedWorkItemForReviewer(?User $reviewer = null, int $rate = 2500): array
    {
        $scene = $this->humanReviewReadyRequest();
        if ($reviewer === null) {
            $profile = $this->reviewerForRequest($scene['request'], ['rate_minor' => $rate, 'max_load' => 16, 'daily_max' => 16]);
            $reviewer = User::query()->findOrFail($profile->user_id);
        } else {
            $this->authorizeExistingReviewer($reviewer, $scene['request']);
        }
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $review = $this->passAll(app(StartHumanReview::class)->execute($assignment, $reviewer), $reviewer);
        app(ApproveHumanReview::class)->execute($review, $reviewer);

        return [ReviewerWorkItem::query()->where('review_id', $review->id)->sole(), $reviewer];
    }

    private function approvedWorkItemForExistingReviewer(User $reviewer, int $rate): ReviewerWorkItem
    {
        return $this->approvedWorkItemForReviewer($reviewer, $rate)[0];
    }

    private function authorizeExistingReviewer(User $reviewer, \App\Models\PlanningRequest $request): void
    {
        DB::table('reviewer_grades')->insert([
            'reviewer_id' => $reviewer->id,
            'grade_id' => $request->grade_id,
            'curriculum_version_id' => $request->curriculum_version_id,
            'created_at' => now(),
        ]);
        ReviewerAvailability::factory()->create([
            'reviewer_id' => $reviewer->id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);
    }

    private function passAll(\App\Models\HumanReview $review, User $reviewer): \App\Models\HumanReview
    {
        $responses = [];
        foreach ($review->checklistVersion->items as $item) {
            $responses[$item->key] = ['passed' => true, 'comment' => null];
        }

        return app(SaveHumanReview::class)->execute($review, $reviewer, $responses);
    }
}
