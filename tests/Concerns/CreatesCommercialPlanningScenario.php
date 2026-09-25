<?php

namespace Tests\Concerns;

use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Commerce\ReservePlanningUnits;
use App\Actions\Planning\AuthorizePlanningRequestForProcessing;
use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Plans\PublishPlanVersion;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageResource;
use App\Exceptions\PlanningCommercialException;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Models\PlanVersion;
use App\Models\SubscriptionPeriod;
use App\Services\Commerce\SubscriptionBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\PedagogyTestCase;

trait CreatesCommercialPlanningScenario
{
    protected function draft(int $days = 28): PlanningRequest
    {
        $this->freezeTime();
        $scene = $this->seedFullTeacher();
        $scene['profile']->update(['student_count' => 25, 'general_level' => 'medio', 'session_minutes' => 50, 'characteristics' => 'Grupo ficticio participativo.']);
        $this->addDefaultPlanningSchedule($scene['user'], $scene['group']);

        $request = PlanningRequest::factory()->create([
            'creation_mode' => 'advanced',
            'owner_id' => $scene['user']->id, 'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id, 'grade_id' => $scene['grade']->id,
            'starts_on' => '2026-10-01', 'ends_on' => \Carbon\CarbonImmutable::parse('2026-10-01')->addDays($days - 1)->toDateString(),
        ]);
        $pda = Pda::where('grade_id', $request->grade_id)->firstOrFail();
        app(SyncPlanningRequestSelections::class)->execute($request->owner, $request, ['contents' => [$pda->curricular_content_id], 'pdas' => [$pda->id]]);

        return $request->refresh();
    }

    protected function period(PlanningRequest $request, array $limits = []): SubscriptionPeriod
    {
        $version = PlanVersion::factory()->create(array_merge([
            'max_planning_days' => 7, 'planning_limit' => 8, 'human_review_limit' => 0,
            'human_review_required' => false, 'correction_limit' => 2, 'group_limit' => 1,
        ], $limits));
        app(PublishPlanVersion::class)($version, $this->admin());
        $subscription = app(CreateSubscription::class)($request->owner, $version->fresh(), now()->subDay());

        return app(OpenSubscriptionPeriod::class)($subscription, now()->subDay(), now()->addDays(30));
    }

    protected function confirm(PlanningRequest $request): PlanningRequest
    {
        return app(ConfirmPlanningRequest::class)->execute($request->owner, $request);
    }

    protected function authorize(PlanningRequest $request): PlanningRequest
    {
        return app(AuthorizePlanningRequestForProcessing::class)->execute($request->owner, $request);
    }

}

