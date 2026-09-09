<?php

namespace Tests\Feature\Review;

use App\Actions\AI\RouteAuditResult;
use App\Actions\Review\AssignReviewer;
use App\Actions\Review\ReassignReviewer;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\ReviewerAssignmentException;
use App\Models\Grade;
use App\Models\ReviewerAssignment;
use App\Models\ReviewerAvailability;
use App\Models\ReviewerProfile;
use App\Models\RequestBlock;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class ReviewerAssignmentTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    public function test_ruteo_a_revision_humana_intenta_asignacion_automaticamente(): void
    {
        $scene = $this->succeededAuditScenario(true, [
            'human_review_required' => true,
            'human_review_limit' => 8,
            'max_planning_days' => 7,
            'planning_limit' => 8,
        ]);
        $profile = $this->reviewerForRequest($scene['request']);

        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        $assignment = ReviewerAssignment::query()->where('request_id', $request->id)->sole();
        $this->assertSame($profile->user_id, $assignment->reviewer_id);
        $this->assertSame(ReviewAssignmentStatus::Assigned, $assignment->status);
        $this->assertSame(UsageReservationStatus::Consumed, $this->humanReservation($request->id)->status);
        $this->assertSame(0, RequestBlock::query()->where('request_id', $request->id)->where('code', 'no_reviewer')->whereNull('resolved_at')->count());
    }

    public function test_asigna_revisor_elegible_y_consume_reserva_humana_una_sola_vez(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['rate_minor' => 1234]);
        $reservation = $this->humanReservation($scene['request']->id);
        $this->assertSame(UsageReservationStatus::Reserved, $reservation->status);

        $assignment = app(AssignReviewer::class)->execute($scene['request']);
        $this->assertNotNull($assignment);
        $this->assertSame($profile->user_id, $assignment->reviewer_id);
        $this->assertSame(4, $assignment->units_snapshot);
        $this->assertSame(1234, $assignment->rate_snapshot_minor);
        $this->assertSame(4936, $assignment->total_fee_minor);
        $this->assertSame('MXN', $assignment->currency);
        $this->assertSame(ReviewAssignmentStatus::Assigned, $assignment->status);
        $this->assertNotNull($assignment->due_at);

        $consumed = $this->humanReservation($scene['request']->id);
        $this->assertSame(UsageReservationStatus::Consumed, $consumed->status);
        $this->assertNotNull($consumed->consumed_at);

        $again = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $this->assertSame($assignment->id, $again?->id);
        $this->assertSame(1, ReviewerAssignment::query()->where('request_id', $scene['request']->id)->count());
        $this->assertSame(1, UsageReservation::query()
            ->where('planning_request_id', $scene['request']->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->count());
    }

    public function test_sin_candidato_abre_bloqueo_y_no_consume_reserva(): void
    {
        $scene = $this->humanReviewReadyRequest();

        $assignment = app(AssignReviewer::class)->execute($scene['request']);

        $this->assertNull($assignment);
        $this->assertSame(UsageReservationStatus::Reserved, $this->humanReservation($scene['request']->id)->status);
        $this->assertDatabaseHas('request_blocks', [
            'request_id' => $scene['request']->id,
            'code' => 'no_reviewer',
            'stage' => 'human_review',
            'resolved_at' => null,
        ]);
    }

    public function test_no_autorizado_para_grado_no_es_candidato(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = ReviewerProfile::factory()->create(['user_id' => $this->reviewer()->id]);
        $otherGrade = Grade::query()
            ->where('curriculum_version_id', $scene['request']->curriculum_version_id)
            ->where('id', '!=', $scene['request']->grade_id)
            ->firstOrFail();
        DB::table('reviewer_grades')->insert([
            'reviewer_id' => $profile->user_id,
            'grade_id' => $otherGrade->id,
            'curriculum_version_id' => $scene['request']->curriculum_version_id,
            'created_at' => now(),
        ]);
        ReviewerAvailability::factory()->create([
            'reviewer_id' => $profile->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);

        $this->assertNull(app(AssignReviewer::class)->execute($scene['request']));
        $this->assertSame(UsageReservationStatus::Reserved, $this->humanReservation($scene['request']->id)->status);
    }

    public function test_disponibilidad_debe_cubrir_ventana_completa(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = ReviewerProfile::factory()->create(['user_id' => $this->reviewer()->id]);
        DB::table('reviewer_grades')->insert([
            'reviewer_id' => $profile->user_id,
            'grade_id' => $scene['request']->grade_id,
            'curriculum_version_id' => $scene['request']->curriculum_version_id,
            'created_at' => now(),
        ]);
        ReviewerAvailability::factory()->create([
            'reviewer_id' => $profile->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addHour(),
        ]);

        $this->assertNull(app(AssignReviewer::class)->execute($scene['request']));
    }

    public function test_capacidad_max_load_y_daily_max_se_miden_en_unidades(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $this->reviewerForRequest($scene['request'], ['max_load' => 3, 'daily_max' => 8]);
        $this->assertNull(app(AssignReviewer::class)->execute($scene['request']));

        ReviewerProfile::query()->delete();
        // CASCADE limpia autorizaciones/disponibilidad; un segundo perfil tiene carga total suficiente
        // pero daily_max menor que las 4 unidades requeridas.
        $this->reviewerForRequest($scene['request'], ['max_load' => 8, 'daily_max' => 3]);
        $this->assertNull(app(AssignReviewer::class)->execute($scene['request']->fresh()));
    }

    public function test_prefiere_menor_carga_relativa_entre_candidatos_elegibles(): void
    {
        $firstScene = $this->humanReviewReadyRequest();
        $busy = $this->reviewerForRequest($firstScene['request'], ['max_load' => 8, 'daily_max' => 8]);
        app(AssignReviewer::class)->execute($firstScene['request']);

        $secondScene = $this->humanReviewReadyRequest();
        DB::table('reviewer_grades')->insertOrIgnore([
            'reviewer_id' => $busy->user_id,
            'grade_id' => $secondScene['request']->grade_id,
            'curriculum_version_id' => $secondScene['request']->curriculum_version_id,
            'created_at' => now(),
        ]);
        $free = $this->reviewerForRequest($secondScene['request'], ['max_load' => 8, 'daily_max' => 8]);

        $assignment = app(AssignReviewer::class)->execute($secondScene['request']->fresh());

        $this->assertNotNull($assignment);
        $this->assertSame($free->user_id, $assignment->reviewer_id);
        $this->assertNotSame($busy->user_id, $assignment->reviewer_id);
    }

    public function test_reasignacion_admin_conserva_historial_y_no_vuelve_a_consumir(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $first = $this->reviewerForRequest($scene['request'], ['rate_minor' => 1000]);
        $initial = app(AssignReviewer::class)->execute($scene['request']);
        $consumedAt = $this->humanReservation($scene['request']->id)->consumed_at?->toISOString();

        $second = $this->reviewerForRequest($scene['request'], ['rate_minor' => 1500]);
        $replacement = app(ReassignReviewer::class)->execute(
            $this->admin(),
            $scene['request']->fresh(),
            'Reasignación operativa de prueba',
        );

        $this->assertNotNull($replacement);
        $this->assertSame($second->user_id, $replacement->reviewer_id);
        $this->assertSame($initial?->cycle, $replacement->cycle);
        $this->assertSame(6000, $replacement->total_fee_minor);
        $this->assertSame(ReviewAssignmentStatus::Reassigned, $initial?->fresh()->status);
        $this->assertNotNull($initial?->fresh()->ended_at);
        $this->assertSame(2, ReviewerAssignment::query()->where('request_id', $scene['request']->id)->count());
        $this->assertSame(1, ReviewerAssignment::query()
            ->where('request_id', $scene['request']->id)
            ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
            ->count());
        $this->assertSame($consumedAt, $this->humanReservation($scene['request']->id)->consumed_at?->toISOString());
        $this->assertSame(1, UsageReservation::query()
            ->where('planning_request_id', $scene['request']->id)
            ->where('resource', UsageResource::HumanReview->value)
            ->count());
        $this->assertSame($first->user_id, $initial?->reviewer_id);
    }

    public function test_reasignacion_requiere_admin_y_motivo(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $this->reviewerForRequest($scene['request']);
        app(AssignReviewer::class)->execute($scene['request']);
        $this->reviewerForRequest($scene['request']);

        try {
            app(ReassignReviewer::class)->execute($this->reviewer(), $scene['request']->fresh(), 'motivo');
            $this->fail('Debe rechazar actor no administrador.');
        } catch (ReviewerAssignmentException $e) {
            $this->assertSame('REVIEW_REASSIGNMENT_ADMIN_REQUIRED', $e->errorCode);
        }

        try {
            app(ReassignReviewer::class)->execute($this->admin(), $scene['request']->fresh(), '   ');
            $this->fail('Debe requerir motivo.');
        } catch (ReviewerAssignmentException $e) {
            $this->assertSame('REVIEW_REASSIGNMENT_REASON_REQUIRED', $e->errorCode);
        }
    }

    public function test_bloqueo_no_reviewer_se_resuelve_cuando_aparece_capacidad(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $this->assertNull(app(AssignReviewer::class)->execute($scene['request']));
        $block = RequestBlock::query()->where('request_id', $scene['request']->id)->where('code', 'no_reviewer')->sole();
        $this->assertNull($block->resolved_at);

        $this->reviewerForRequest($scene['request']);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());

        $this->assertNotNull($assignment);
        $this->assertNotNull($block->fresh()->resolved_at);
    }

    private function humanReservation(int $requestId): UsageReservation
    {
        return UsageReservation::query()
            ->where('planning_request_id', $requestId)
            ->where('resource', UsageResource::HumanReview->value)
            ->sole();
    }
}
