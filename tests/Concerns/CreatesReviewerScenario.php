<?php

namespace Tests\Concerns;

use App\Actions\AI\RouteAuditResult;
use App\Enums\ReviewerProfileStatus;
use App\Models\PlanningRequest;
use App\Models\ReviewerAvailability;
use App\Models\ReviewerProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;

trait CreatesReviewerScenario
{
    /** @return array{request:PlanningRequest,version:\App\Models\DocumentVersion,audit:\App\Models\AiExecution} */
    protected function humanReviewReadyRequest(int $days = 28): array
    {
        $scene = $this->succeededAuditScenario(true, [
            'human_review_required' => true,
            'human_review_limit' => 8,
            'max_planning_days' => 7,
            'planning_limit' => 8,
        ]);
        $request = app(RouteAuditResult::class)->execute($scene['audit']->fresh());

        return [
            'request' => $request->fresh(['document']),
            'version' => $scene['version']->fresh(),
            'audit' => $scene['audit']->fresh(),
        ];
    }

    protected function reviewerForRequest(
        PlanningRequest $request,
        array $profile = [],
        ?User $user = null,
    ): ReviewerProfile {
        $user ??= $this->reviewer();
        $reviewer = ReviewerProfile::factory()->create(array_merge([
            'user_id' => $user->id,
            'status' => ReviewerProfileStatus::Active->value,
            'max_load' => 8,
            'daily_max' => 8,
            'rate_minor' => 2500,
            'currency' => 'MXN',
        ], $profile));

        DB::table('reviewer_grades')->insert([
            'reviewer_id' => $reviewer->user_id,
            'grade_id' => $request->grade_id,
            'curriculum_version_id' => $request->curriculum_version_id,
            'created_at' => now(),
        ]);
        ReviewerAvailability::factory()->create([
            'reviewer_id' => $reviewer->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);

        return $reviewer->fresh();
    }
}
