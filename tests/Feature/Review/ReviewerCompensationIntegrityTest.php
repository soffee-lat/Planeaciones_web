<?php

namespace Tests\Feature\Review;

use App\Actions\Review\ApproveHumanReview;
use App\Actions\Review\ApproveReviewerSettlement;
use App\Actions\Review\AssignReviewer;
use App\Actions\Review\CreateReviewerSettlement;
use App\Actions\Review\MarkReviewerSettlementPaid;
use App\Actions\Review\SaveHumanReview;
use App\Actions\Review\StartHumanReview;
use App\Models\ReviewerWorkItem;
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

class ReviewerCompensationIntegrityTest extends PedagogyTestCase
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
    }

    public function test_bd_congela_identidad_del_trabajo_pagable(): void
    {
        [$item] = $this->approvedWorkItem();

        $this->assertDbErrorContains('REVIEWER_WORK_ITEM_IDENTITY_IMMUTABLE', function () use ($item): void {
            DB::table('reviewer_work_items')->where('id', $item->id)->update(['rate_minor' => $item->rate_minor + 1]);
        });
        $this->assertDbErrorContains('REVIEWER_WORK_ITEM_HISTORY_IMMUTABLE', function () use ($item): void {
            DB::table('reviewer_work_items')->where('id', $item->id)->delete();
        });
    }

    public function test_bd_rechaza_liquidar_trabajo_en_settlement_de_otro_revisor(): void
    {
        [$first] = $this->approvedWorkItem();
        [, $secondReviewer] = $this->approvedWorkItem();
        $admin = $this->admin();
        $otherSettlement = app(CreateReviewerSettlement::class)->execute($secondReviewer, $admin);

        $this->assertDbErrorContains('REVIEWER_WORK_ITEM_SETTLEMENT_MISMATCH', function () use ($first, $otherSettlement): void {
            DB::transaction(function () use ($first, $otherSettlement): void {
                DB::table('reviewer_work_items')->where('id', $first->id)->update([
                    'settlement_id' => $otherSettlement->id,
                    'updated_at' => now(),
                ]);
            });
        });
    }

    public function test_bd_rechaza_settlement_pagado_si_sus_items_no_estan_pagados(): void
    {
        [, $reviewer] = $this->approvedWorkItem();
        $admin = $this->admin();
        $settlement = app(CreateReviewerSettlement::class)->execute($reviewer, $admin);
        $settlement = app(ApproveReviewerSettlement::class)->execute($settlement, $admin);

        $this->assertDbErrorContains('REVIEWER_SETTLEMENT_ITEMS_NOT_PAID', function () use ($settlement, $admin): void {
            DB::transaction(function () use ($settlement, $admin): void {
                DB::table('reviewer_settlements')->where('id', $settlement->id)->update([
                    'status' => 'paid',
                    'reference' => 'FAKE-PAYMENT',
                    'paid_by' => $admin->id,
                    'paid_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        });
    }

    public function test_bd_congela_trabajo_y_liquidacion_despues_de_pago(): void
    {
        [$item, $reviewer] = $this->approvedWorkItem();
        $admin = $this->admin();
        $settlement = app(CreateReviewerSettlement::class)->execute($reviewer, $admin);
        $settlement = app(ApproveReviewerSettlement::class)->execute($settlement, $admin);
        $settlement = app(MarkReviewerSettlementPaid::class)->execute($settlement, $admin, 'FINAL-1');

        $this->assertDbErrorContains('REVIEWER_WORK_ITEM_PAID_IMMUTABLE', function () use ($item): void {
            DB::table('reviewer_work_items')->where('id', $item->id)->update(['paid_at' => now()->addSecond()]);
        });
        $this->assertDbErrorContains('REVIEWER_SETTLEMENT_PAID_IMMUTABLE', function () use ($settlement): void {
            DB::table('reviewer_settlements')->where('id', $settlement->id)->update(['reference' => 'FINAL-2']);
        });
    }

    /** @return array{0:ReviewerWorkItem,1:User} */
    private function approvedWorkItem(): array
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request'], ['max_load' => 16, 'daily_max' => 16]);
        $assignment = app(AssignReviewer::class)->execute($scene['request']->fresh());
        $reviewer = User::query()->findOrFail($profile->user_id);
        $review = app(StartHumanReview::class)->execute($assignment, $reviewer);
        $responses = [];
        foreach ($review->checklistVersion->items as $check) {
            $responses[$check->key] = ['passed' => true, 'comment' => null];
        }
        $review = app(SaveHumanReview::class)->execute($review, $reviewer, $responses);
        app(ApproveHumanReview::class)->execute($review, $reviewer);

        return [ReviewerWorkItem::query()->where('review_id', $review->id)->sole(), $reviewer];
    }

    private function assertDbErrorContains(string $needle, callable $callback): void
    {
        try {
            DB::transaction($callback);
            $this->fail('Se esperaba error PostgreSQL con '.$needle);
        } catch (QueryException|PDOException $e) {
            $this->assertStringContainsString($needle, $e->getMessage());
        }
    }
}
