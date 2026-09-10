<?php

namespace Tests\Feature\Review;

use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Actions\Review\AssignReviewer;
use App\Actions\Review\EscalateHumanReview;
use App\Actions\Review\RejectHumanReview;
use App\Actions\Review\RequestHumanReviewCorrection;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Enums\AiExecutionStage;
use App\Enums\HumanReviewStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\UsageResource;
use App\Enums\UsageReservationStatus;
use App\Exceptions\HumanReviewException;
use App\Exceptions\ReviewerAssignmentException;
use App\Models\AiExecution;
use App\Models\HumanReview;
use App\Models\OutboxEvent;
use App\Models\RequestBlock;
use App\Models\ReviewerAssignment;
use App\Models\UsageReservation;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class HumanReviewDecisionTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    public function test_revisor_solicita_correccion_acotada_sin_consumo_humano_extra(): void
    {
        [$review, $assignment, $reviewer] = $this->reviewWithFailedActivity('Ajustar la secuencia de actividades.');
        $reservation = UsageReservation::query()
            ->where('planning_request_id', $review->request_id)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
        $consumedAt = $reservation->consumed_at?->toISOString();

        $request = app(RequestHumanReviewCorrection::class)->execute(
            $review,
            $reviewer,
            '61616161-6161-4161-8161-616161616161',
        );

        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $request->status);
        $this->assertSame(HumanReviewStatus::ChangesRequested, $review->fresh()->status);
        $this->assertSame(ReviewAssignmentStatus::Completed, $assignment->fresh()->status);
        $this->assertSame('human_review_changes_requested', $assignment->fresh()->ended_reason);

        $correction = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        $this->assertSame('human_review', $correction->input_manifest['source_kind']);
        $this->assertSame($review->id, $correction->input_manifest['source_review_id']);
        $this->assertSame(['sessions'], $correction->input_manifest['section_keys']);
        $this->assertDatabaseHas('outbox_events', [
            'type' => OutboxEventType::PlanningCorrectionRequested->value,
            'aggregate_id' => $request->id,
        ]);
        $this->assertDatabaseHas('request_state_events', [
            'request_id' => $request->id,
            'from_status' => PlanningRequestStatus::REVISION_HUMANA->value,
            'to_status' => PlanningRequestStatus::CORRECCION_IA->value,
            'reason' => 'human_review_changes_requested',
            'actor_id' => $reviewer->id,
        ]);

        $reservation->refresh();
        $this->assertSame(UsageReservationStatus::Consumed, $reservation->status);
        $this->assertSame($consumedAt, $reservation->consumed_at?->toISOString());
        $this->assertSame(1, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->count());
    }

    public function test_solicitar_correccion_es_idempotente(): void
    {
        [$review, , $reviewer] = $this->reviewWithFailedActivity('Corregir actividades.');
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);
        app(RequestHumanReviewCorrection::class)->execute($review->fresh(), $reviewer);

        $this->assertSame(1, AiExecution::query()
            ->where('request_id', $review->request_id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->where('input_manifest->source_kind', 'human_review')
            ->count());
        $this->assertSame(1, OutboxEvent::query()
            ->where('aggregate_id', $review->request_id)
            ->where('type', OutboxEventType::PlanningCorrectionRequested->value)
            ->count());
    }

    public function test_fallo_curricular_congelado_no_puede_enviarse_a_correccion_automatica(): void
    {
        [$review, , $reviewer] = $this->startedReview();
        $responses = $this->allPassedResponses($review);
        $responses['grade'] = ['passed' => false, 'comment' => 'El grado no coincide.'];
        $review = app(SaveHumanReview::class)->execute($review, $reviewer, $responses, [
            'sessions' => 'También revisar actividades.',
        ]);

        $this->expectException(HumanReviewException::class);
        $this->expectExceptionMessage('HUMAN_REVIEW_CORRECTION_SCOPE_UNSAFE');
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);
    }

    public function test_correccion_requiere_observacion_en_seccion_mutable(): void
    {
        [$review, , $reviewer] = $this->startedReview();
        $responses = $this->allPassedResponses($review);
        $responses['activities'] = ['passed' => false, 'comment' => 'Necesita ajustes.'];
        $review = app(SaveHumanReview::class)->execute($review, $reviewer, $responses);

        $this->expectException(HumanReviewException::class);
        $this->expectExceptionMessage('HUMAN_REVIEW_CORRECTION_SECTION_COMMENT_REQUIRED');
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);
    }

    public function test_correccion_humana_genera_nueva_version_y_reauditoria(): void
    {
        [$review, , $reviewer] = $this->reviewWithFailedActivity('Reescribir la actividad inicial.');
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);
        $correction = AiExecution::query()
            ->where('request_id', $review->request_id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        $event = OutboxEvent::query()->where('event_key', 'ai-execution:' . $correction->id . ':correction-dispatch')->sole();
        app(ProcessOutboxEvent::class)->execute($event);

        $package = $correction->fresh()->manualPackage;
        $payload = json_decode(Storage::disk($package->disk)->get($package->path), true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('human_review', $payload['source']['kind']);
        $this->assertSame($review->id, $payload['source']['review_id']);

        $source = $review->version()->firstOrFail();
        $sessions = $source->content['sessions'];
        $sessions[0]['moments'][0]['activities'][0]['instruction'] = 'Actividad corregida por observación humana.';
        $newVersion = app(ImportManualCorrectionResult::class)->execute($correction->fresh(), [
            'schema_version' => 'correction_result_v1',
            'source_version_id' => $source->id,
            'patch' => ['sessions' => $sessions],
        ]);

        $this->assertSame($source->id, $newVersion->parent_version_id);
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $review->request->fresh()->status);
        $this->assertDatabaseHas('ai_executions', [
            'request_id' => $review->request_id,
            'stage' => AiExecutionStage::Audit->value,
            'status' => 'pending',
        ]);
    }

    public function test_reauditoria_aprobada_regresa_al_mismo_revisor_con_nueva_review_sin_respuestas_heredadas(): void
    {
        [$review, $oldAssignment, $reviewer] = $this->reviewWithFailedActivity('Rehacer la actividad.');
        app(RequestHumanReviewCorrection::class)->execute($review, $reviewer);
        $correction = AiExecution::query()
            ->where('request_id', $review->request_id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $correction->id . ':correction-dispatch')->sole(),
        );

        $source = $review->version()->firstOrFail();
        $sessions = $source->content['sessions'];
        $sessions[0]['moments'][0]['activities'][0]['instruction'] = 'Versión corregida para segunda revisión.';
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
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $audit->id . ':audit-dispatch')->sole(),
        );
        app(ImportManualAuditResult::class)->execute($audit->fresh(), [
            'schema_version' => 'audit_result_v1',
            'passed' => true,
            'findings' => [],
        ]);
        $request = app(RouteAuditResult::class)->execute($audit->fresh());

        $this->assertSame(PlanningRequestStatus::REVISION_HUMANA, $request->status);
        $newAssignment = ReviewerAssignment::query()
            ->where('request_id', $request->id)
            ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
            ->sole();
        $this->assertNotSame($oldAssignment->id, $newAssignment->id);
        $this->assertSame($reviewer->id, $newAssignment->reviewer_id);
        $this->assertSame($oldAssignment->cycle, $newAssignment->cycle);

        $secondReview = app(StartHumanReview::class)->execute($newAssignment, $reviewer);
        $this->assertSame($newVersion->id, $secondReview->version_id);
        $this->assertSame(HumanReviewStatus::InProgress, $secondReview->status);
        $this->assertSame(0, $secondReview->responses()->count());
        $this->assertSame(2, HumanReview::query()->where('request_id', $request->id)->count());
        $this->assertSame(1, UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->count());
    }

    public function test_escalar_cierra_asignacion_y_abre_bloqueo_administrativo(): void
    {
        [$review, $assignment, $reviewer] = $this->startedReview();
        $request = app(EscalateHumanReview::class)->execute($review, $reviewer, 'Requiere criterio editorial de administración.');

        $this->assertSame(PlanningRequestStatus::REVISION_HUMANA, $request->status);
        $this->assertSame(HumanReviewStatus::Escalated, $review->fresh()->status);
        $this->assertSame(ReviewAssignmentStatus::Cancelled, $assignment->fresh()->status);
        $block = RequestBlock::query()->where('request_id', $request->id)
            ->where('code', 'human_review_attention')->whereNull('resolved_at')->sole();
        $this->assertSame('escalated', $block->details['decision']);

        $this->expectException(ReviewerAssignmentException::class);
        app(AssignReviewer::class)->execute($request->fresh());
    }

    public function test_rechazar_registra_decision_terminal_sin_cancelar_solicitud(): void
    {
        [$review, $assignment, $reviewer] = $this->startedReview();
        $request = app(RejectHumanReview::class)->execute($review, $reviewer, 'No es seguro aprobar ni autocorregir este contenido.');

        $this->assertSame(PlanningRequestStatus::REVISION_HUMANA, $request->status);
        $this->assertSame(HumanReviewStatus::Rejected, $review->fresh()->status);
        $this->assertSame(ReviewAssignmentStatus::Cancelled, $assignment->fresh()->status);
        $this->assertDatabaseHas('request_blocks', [
            'request_id' => $request->id,
            'code' => 'human_review_attention',
            'stage' => 'human_review',
        ]);
    }

    /** @return array{0:HumanReview,1:ReviewerAssignment,2:User} */
    private function startedReview(): array
    {
        config(['ai.human_review_correction.max_rounds' => 3]);
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $reviewer = User::query()->findOrFail($profile->user_id);
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);

        return [$review, $assignment, $reviewer];
    }

    /** @return array{0:HumanReview,1:ReviewerAssignment,2:User} */
    private function reviewWithFailedActivity(string $sectionComment): array
    {
        [$review, $assignment, $reviewer] = $this->startedReview();
        $responses = $this->allPassedResponses($review);
        $responses['activities'] = ['passed' => false, 'comment' => 'La actividad necesita ajuste.'];
        $review = app(SaveHumanReview::class)->execute($review, $reviewer, $responses, [
            'sessions' => $sectionComment,
        ], 'Solicito una corrección acotada.');

        return [$review, $assignment, $reviewer];
    }

    /** @return array<string,array{passed:bool,comment:?string}> */
    private function allPassedResponses(HumanReview $review): array
    {
        $responses = [];
        foreach ($review->checklistVersion->items as $item) {
            $responses[$item->key] = ['passed' => true, 'comment' => null];
        }

        return $responses;
    }
}
