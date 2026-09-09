<?php

namespace Tests\Feature\Review;

use App\Actions\Review\ApproveHumanReview;
use App\Actions\Review\AssignReviewer;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Enums\ApprovalKind;
use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Exceptions\HumanReviewException;
use App\Models\Approval;
use App\Models\HumanReview;
use App\Models\ReviewChecklistResponse;
use App\Models\User;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class HumanReviewExecutionTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    public function test_iniciar_revision_congela_version_y_checklist_publicado(): void
    {
        [$assignment, $reviewer] = $this->assignedReview();

        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);

        $this->assertSame(HumanReviewStatus::InProgress, $review->status);
        $this->assertSame((int) $assignment->request->document->current_version_id, (int) $review->version_id);
        $this->assertSame('published', $review->checklistVersion->status->value);
        $this->assertCount(15, $review->checklistVersion->items);
        $this->assertSame(ReviewAssignmentStatus::InProgress, $assignment->fresh()->status);
    }

    public function test_iniciar_revision_es_idempotente_para_la_misma_asignacion(): void
    {
        [$assignment, $reviewer] = $this->assignedReview();
        $first = app(StartHumanReview::class)->execute($assignment, $reviewer);
        $second = app(StartHumanReview::class)->execute($assignment->fresh(), $reviewer);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, HumanReview::query()->where('assignment_id', $assignment->id)->count());
    }

    public function test_otro_revisor_no_puede_iniciar_trabajo_ajeno(): void
    {
        [$assignment] = $this->assignedReview();
        $other = $this->reviewer();

        $this->expectException(HumanReviewException::class);
        $this->expectExceptionMessage('HUMAN_REVIEW_REVIEWER_FORBIDDEN');
        app(StartHumanReview::class)->execute($assignment, $other);
    }

    public function test_guarda_respuestas_y_comentarios_estructurados(): void
    {
        [$assignment, $reviewer] = $this->assignedReview();
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);
        $items = $review->checklistVersion->items;

        $saved = app(SaveHumanReview::class)->execute($review, $reviewer, [
            $items[0]->key => ['passed' => true, 'comment' => 'Correcto.'],
            $items[1]->key => ['passed' => false, 'comment' => 'Revisar fecha.'],
        ], [
            'planning' => 'El periodo necesita una segunda lectura.',
            'sessions' => 'La secuencia está bien organizada.',
        ], 'Avance parcial de revisión.');

        $this->assertSame('Avance parcial de revisión.', $saved->general_comment);
        $this->assertSame('El periodo necesita una segunda lectura.', $saved->section_comments['planning']);
        $this->assertSame(2, ReviewChecklistResponse::query()->where('review_id', $review->id)->count());
        $this->assertFalse((bool) ReviewChecklistResponse::query()->where('review_id', $review->id)->where('checklist_item_id', $items[1]->id)->value('passed'));
    }

    public function test_no_aprueba_con_checklist_obligatorio_incompleto(): void
    {
        [$assignment, $reviewer] = $this->assignedReview();
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);

        $this->expectException(HumanReviewException::class);
        $this->expectExceptionMessage('HUMAN_REVIEW_CHECKLIST_INCOMPLETE');
        app(ApproveHumanReview::class)->execute($review, $reviewer);
    }

    public function test_aprobacion_humana_completa_cierra_asignacion_y_mueve_solicitud(): void
    {
        [$assignment, $reviewer] = $this->assignedReview();
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);
        $review = $this->passAll($review, $reviewer);

        $request = app(ApproveHumanReview::class)->execute(
            $review,
            $reviewer,
            '51515151-5151-4151-8151-515151515151',
        );

        $this->assertSame(PlanningRequestStatus::APROBADA, $request->status);
        $this->assertSame(HumanReviewStatus::Approved, $review->fresh()->status);
        $this->assertSame(ReviewAssignmentStatus::Completed, $assignment->fresh()->status);
        $this->assertDatabaseHas('approvals', [
            'request_id' => $request->id,
            'version_id' => $review->version_id,
            'kind' => ApprovalKind::Human->value,
            'review_id' => $review->id,
            'actor_id' => $reviewer->id,
        ]);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $request->id,
            'from_status' => PlanningRequestStatus::REVISION_HUMANA->value,
            'to_status' => PlanningRequestStatus::APROBADA->value,
            'reason' => 'human_review_approved',
            'actor_id' => $reviewer->id,
        ]);
    }

    public function test_aprobar_dos_veces_no_duplica_aprobacion(): void
    {
        [$assignment, $reviewer] = $this->assignedReview();
        $review = $this->passAll(app(StartHumanReview::class)->execute($assignment, $reviewer), $reviewer);

        app(ApproveHumanReview::class)->execute($review, $reviewer);
        app(ApproveHumanReview::class)->execute($review->fresh(), $reviewer);

        $this->assertSame(1, Approval::query()->where('version_id', $review->version_id)->where('kind', ApprovalKind::Human->value)->count());
        $this->assertSame(1, $review->request->stateEvents()->where('reason', 'human_review_approved')->count());
    }

    /** @return array{0:\App\Models\ReviewerAssignment,1:User} */
    private function assignedReview(): array
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $this->assertNotNull($assignment);

        return [$assignment->fresh(['request.document.currentVersion']), User::query()->findOrFail($profile->user_id)];
    }

    private function passAll(HumanReview $review, User $reviewer): HumanReview
    {
        $responses = [];
        foreach ($review->checklistVersion->items as $item) {
            $responses[$item->key] = ['passed' => true, 'comment' => null];
        }

        return app(SaveHumanReview::class)->execute($review, $reviewer, $responses);
    }
}
