<?php

namespace Tests\Feature\Commerce;

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

class PlanningCommercialAuthorizationTest extends PedagogyTestCase
{
    use \Tests\Concerns\CreatesCommercialPlanningScenario;

    protected function assertBlocked(PlanningRequest $request, string $code): PlanningCommercialException
    {
        $before = $request->fresh()->getAttributes();
        $reservationCount = DB::table('usage_reservations')->count();
        $events = $request->stateEvents()->count();
        try {
            $this->authorize($request);
            $this->fail('Authorization should fail.');
        } catch (PlanningCommercialException $error) {
            $this->assertSame($code, $error->getMessage());
        }
        $this->assertSame($before, $request->fresh()->getAttributes());
        $this->assertSame($reservationCount, DB::table('usage_reservations')->count());
        $this->assertSame($events, $request->stateEvents()->count());
        $this->assertSame(0, $request->segments()->count());

        return $error;
    }

    public static function durations(): array
    {
        return [[1, 1], [7, 1], [8, 2], [14, 2], [15, 3], [28, 4], [31, 5]];
    }

    #[DataProvider('durations')]
    public function test_authorization_freezes_inclusive_units_and_exact_segments(int $days, int $units): void
    {
        $request = $this->draft($days);
        $period = $this->period($request);
        $this->confirm($request);
        Queue::fake();

        $authorized = $this->authorize($request);

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $authorized->status);
        $this->assertSame($days, $authorized->planning_days);
        $this->assertSame($units, $authorized->planning_units);
        $this->assertSame('calendar_days_v1', $authorized->calculation_strategy);
        $this->assertSame($period->id, $authorized->subscription_period_id);
        $this->assertSame($units, $authorized->segments()->count());
        $this->assertSame($days, (int) $authorized->segments()->sum('calendar_days'));
        $segments = $authorized->segments;
        $this->assertSame('2026-10-01', $segments->first()->starts_on->toDateString());
        $this->assertSame($request->ends_on->toDateString(), $segments->last()->ends_on->toDateString());
        foreach ($segments as $i => $segment) {
            $this->assertLessThanOrEqual(7, $segment->calendar_days);
            if ($i > 0) {
                $this->assertSame($segments[$i - 1]->ends_on->copy()->addDay()->toDateString(), $segment->starts_on->toDateString());
            }
        }
        $this->assertSame(1, $authorized->usageReservations()->count());
        $this->assertDatabaseHas('usage_reservations', ['planning_request_id' => $request->id, 'resource' => 'planning', 'quantity' => $units, 'status' => 'reserved', 'consumed_at' => null]);
        $this->assertSame(0, app(SubscriptionBalance::class)->forPeriod($period)['planning']->consumed);
        Queue::assertNothingPushed();
    }

    public function test_missing_rights_preserves_confirmation_and_later_retry_authorizes(): void
    {
        $request = $this->confirm($this->draft());
        $this->assertBlocked($request, 'COMMERCIAL_RIGHTS_REQUIRED');
        $this->period($request);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $this->authorize($request)->status);
    }

    public function test_reviewed_reserves_both_resources_atomically_and_corrections_are_per_request(): void
    {
        $request = $this->draft();
        $period = $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);
        $request = $this->confirm($request);
        $authorized = $this->authorize($request);

        $this->assertSame(2, $authorized->correction_limit_snapshot);
        $this->assertTrue($authorized->human_review_required_snapshot);
        $this->assertSame(2, $authorized->usageReservations()->count());
        $this->assertDatabaseHas('usage_reservations', ['planning_request_id' => $request->id, 'resource' => 'human_review', 'quantity' => 4, 'status' => 'reserved', 'consumed_at' => null]);
        $this->assertDatabaseMissing('usage_reservations', ['resource' => 'client_correction']);
        $this->assertSame(0, app(SubscriptionBalance::class)->forPeriod($period)['human_review']->consumed);
    }

    public function test_human_shortage_rolls_back_planning_reservation(): void
    {
        $request = $this->draft();
        $period = $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);
        app(ReservePlanningUnits::class)($period, UsageResource::HumanReview, 7, 'prior-review');
        $request = $this->confirm($request);

        $error = $this->assertBlocked($request, 'INSUFFICIENT_HUMAN_REVIEW_UNITS');

        $this->assertSame(4, $error->needed);
        $this->assertSame(1, $error->available);
        $this->assertSame(8, app(SubscriptionBalance::class)->forPeriod($period)['planning']->available());
    }

    public function test_planning_shortage_reports_required_and_available_without_writes(): void
    {
        $request = $this->draft();
        $this->period($request, ['planning_limit' => 3]);
        $error = $this->assertBlocked($this->confirm($request), 'INSUFFICIENT_PLANNING_UNITS');
        $this->assertSame(4, $error->needed);
        $this->assertSame(3, $error->available);
    }

    public function test_group_limit_blocks_until_customer_archives_an_excess_group(): void
    {
        $request = $this->draft();
        $this->period($request);
        $group = $request->group->replicate();
        $group->name = 'Otro grupo ficticio';
        $group->save();
        $request = $this->confirm($request);
        $this->assertBlocked($request, 'GROUP_LIMIT_EXCEEDED');
        $this->assertNull($group->fresh()->archived_at);
        $group->update(['archived_at' => now()]);
        $this->assertNotNull($this->authorize($request)->commercial_authorized_at);
    }

    public function test_draft_and_missing_snapshot_cannot_be_authorized(): void
    {
        $request = $this->draft();
        $this->period($request);
        $this->assertBlocked($request, 'PLANNING_REQUEST_NOT_CONFIRMED');
        $request->update(['status' => PlanningRequestStatus::ESPERANDO_PAGO]);
        $this->assertBlocked($request, 'PLANNING_REQUEST_NOT_CONFIRMED');
    }

    public function test_historical_version_and_idempotent_retry_survive_new_version_and_expiry(): void
    {
        $request = $this->draft();
        $period = $this->period($request);
        $this->confirm($request);
        $new = PlanVersion::factory()->create(['plan_id' => $period->plan_id, 'number' => 2, 'max_planning_days' => 28]);
        app(PublishPlanVersion::class)($new, $this->admin());
        $first = $this->authorize($request);
        $later = PlanVersion::factory()->create(['plan_id' => $period->plan_id, 'number' => 3, 'max_planning_days' => 31, 'correction_limit' => 9]);
        app(PublishPlanVersion::class)($later, $this->admin());
        $this->assertSame($period->plan_version_id, $first->plan_version_id);
        $this->assertSame(4, $first->planning_units);
        $this->assertSame($period->planVersion->checksum, $first->calculation_snapshot['plan_version']['checksum']);
        $this->assertSame($period->fresh()->entitlement_snapshot, $first->calculation_snapshot['entitlements']);
        $period->planVersion->plan->update(['name' => 'Nombre futuro']);
        $this->travel(40)->days();

        $repeated = $this->authorize($request);

        $this->assertSame($first->getAttributes(), $repeated->getAttributes());
        $this->assertSame(1, $request->usageReservations()->count());
        $this->assertSame(2, $request->stateEvents()->count());
        $this->assertSame(4, $request->segments()->count());
    }

    public static function invalidRights(): array
    {
        return [['past_due'], ['cancelled'], ['expired'], ['pending'], ['period-ended'], ['period-expired'], ['period-future'], ['subscription-future'], ['subscription-ended']];
    }

    #[DataProvider('invalidRights')]
    public function test_only_active_subscription_and_current_period_grant_new_rights(string $condition): void
    {
        $request = $this->draft();
        $period = $this->period($request);
        match ($condition) {
            'period-ended' => $period->update(['status' => 'ended']),
            'period-expired' => $this->travel(31)->days(),
            'period-future' => $period->update(['starts_at' => now()->addDay()]),
            'subscription-future' => $period->subscription->update(['starts_at' => now()->addDay()]),
            'subscription-ended' => $period->subscription->update(['ends_at' => now()]),
            default => $period->subscription->update(['status' => $condition]),
        };
        $this->assertBlocked($this->confirm($request), 'COMMERCIAL_RIGHTS_REQUIRED');
    }

    public function test_policy_allows_only_verified_active_owner_and_not_administrator(): void
    {
        $request = $this->confirm($this->draft());
        $this->assertTrue(Gate::forUser($request->owner)->allows('authorizeProcessing', $request));
        foreach ([$this->customer(), $this->admin(), $this->reviewer()] as $actor) {
            $this->assertFalse(Gate::forUser($actor)->allows('authorizeProcessing', $request));
            try {
                app(AuthorizePlanningRequestForProcessing::class)->execute($actor, $request);
                $this->fail('Non-owner must be refused.');
            } catch (\Illuminate\Auth\Access\AuthorizationException) {
                $this->assertNull($request->fresh()->commercial_authorized_at);
            }
        }
        $request->owner->forceFill(['status' => 'suspended'])->save();
        $this->assertFalse(Gate::forUser($request->owner->fresh())->allows('authorizeProcessing', $request));
    }

    public function test_postgresql_prevents_authorized_snapshot_and_segment_mutations(): void
    {
        $request = $this->draft();
        $this->period($request);
        $this->confirm($request);
        $request = $this->authorize($request);
        foreach (['snapshot', 'segment-update', 'segment-delete', 'segment-insert'] as $mutation) {
            try {
                DB::transaction(function () use ($mutation, $request): void {
                    match ($mutation) {
                        'snapshot' => DB::table('planning_requests')->where('id', $request->id)->update(['planning_units' => 1]),
                        'segment-update' => $request->segments()->update(['calendar_days' => 1]),
                        'segment-delete' => $request->segments()->delete(),
                        'segment-insert' => $request->segments()->create(['sequence' => 5, 'starts_on' => '2026-10-28', 'ends_on' => '2026-10-28', 'calendar_days' => 1]),
                    };
                });
                $this->fail('Frozen commercial data must be immutable.');
            } catch (\Illuminate\Database\QueryException $error) {
                $this->assertStringContainsString('IMMUTABLE', $error->getMessage());
            }
        }
    }
}

