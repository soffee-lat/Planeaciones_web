<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\ConsumePlanningReservation;
use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Commerce\ReleasePlanningReservation;
use App\Actions\Commerce\ReservePlanningUnits;
use App\Enums\SubscriptionPeriodStatus;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\SubscriptionPeriod;
use App\Services\Commerce\SubscriptionBalance;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class UsageLedgerTest extends PedagogyTestCase
{
    use RefreshDatabase;

    private function activePeriod(array $planVersionOverrides = []): SubscriptionPeriod
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(array_merge([
            'plan_id' => $plan->id, 'number' => 1,
            'planning_limit' => 4, 'human_review_limit' => 0,
        ], $planVersionOverrides));
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $customer = $this->customer();
        $sub = (new CreateSubscription())($customer, $pv->fresh(), now());
        return (new OpenSubscriptionPeriod())($sub, now()->subDay(), now()->addDays(30));
    }

    public function test_balance_starts_at_full_limit_with_zero_reserved_and_consumed(): void
    {
        $period = $this->activePeriod();
        $balances = (new SubscriptionBalance())->forPeriod($period);
        $this->assertSame(4, $balances['planning']->limit);
        $this->assertSame(0, $balances['planning']->reserved);
        $this->assertSame(0, $balances['planning']->consumed);
        $this->assertSame(4, $balances['planning']->available());
    }

    public function test_reserve_reduces_available_and_consume_keeps_it_reduced(): void
    {
        $period = $this->activePeriod();
        $reserve = new ReservePlanningUnits();

        $r = $reserve($period, UsageResource::Planning, 2, 'op-1');
        $balances = (new SubscriptionBalance())->forPeriod($period);
        $this->assertSame(2, $balances['planning']->reserved);
        $this->assertSame(0, $balances['planning']->consumed);
        $this->assertSame(2, $balances['planning']->available());

        (new ConsumePlanningReservation())($r);
        $balances = (new SubscriptionBalance())->forPeriod($period);
        $this->assertSame(0, $balances['planning']->reserved);
        $this->assertSame(2, $balances['planning']->consumed);
        $this->assertSame(2, $balances['planning']->available());
    }

    public function test_release_returns_units_to_available(): void
    {
        $period = $this->activePeriod();
        $reserve = new ReservePlanningUnits();

        $r = $reserve($period, UsageResource::Planning, 3, 'op-1');
        $this->assertSame(1, (new SubscriptionBalance())->forPeriod($period)['planning']->available());

        (new ReleasePlanningReservation())($r);
        $this->assertSame(4, (new SubscriptionBalance())->forPeriod($period)['planning']->available());
    }

    public function test_reserve_beyond_limit_fails_with_insufficient_units(): void
    {
        $period = $this->activePeriod();
        $reserve = new ReservePlanningUnits();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INSUFFICIENT_PLANNING_UNITS');
        $reserve($period, UsageResource::Planning, 5, 'op-1');
    }

    public function test_reserve_partial_then_over_remaining_fails(): void
    {
        $period = $this->activePeriod();
        $reserve = new ReservePlanningUnits();

        $reserve($period, UsageResource::Planning, 3, 'op-1');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('INSUFFICIENT_PLANNING_UNITS');
        $reserve($period, UsageResource::Planning, 2, 'op-2');
    }

    public function test_reserve_is_idempotent_when_same_operation_key_and_payload(): void
    {
        $period = $this->activePeriod();
        $reserve = new ReservePlanningUnits();

        $r1 = $reserve($period, UsageResource::Planning, 2, 'op-idem');
        $r2 = $reserve($period, UsageResource::Planning, 2, 'op-idem');

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(2, (new SubscriptionBalance())->forPeriod($period)['planning']->reserved);
    }

    public function test_reserve_same_key_different_payload_throws_idempotency_conflict(): void
    {
        $period = $this->activePeriod();
        $reserve = new ReservePlanningUnits();
        $reserve($period, UsageResource::Planning, 2, 'op-conflict');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('USAGE_IDEMPOTENCY_CONFLICT');
        $reserve($period, UsageResource::Planning, 3, 'op-conflict');
    }

    public function test_consume_is_idempotent_and_release_after_consume_fails(): void
    {
        $period = $this->activePeriod();
        $r = (new ReservePlanningUnits())($period, UsageResource::Planning, 1, 'op-consume');
        $consumed1 = (new ConsumePlanningReservation())($r);
        $consumed2 = (new ConsumePlanningReservation())($r);
        $this->assertSame(UsageReservationStatus::Consumed, $consumed1->status);
        $this->assertSame($consumed1->consumed_at?->toIso8601String(), $consumed2->consumed_at?->toIso8601String());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('USAGE_RESERVATION_ALREADY_CONSUMED');
        (new ReleasePlanningReservation())($r);
    }

    public function test_release_is_idempotent_and_consume_after_release_fails(): void
    {
        $period = $this->activePeriod();
        $r = (new ReservePlanningUnits())($period, UsageResource::Planning, 1, 'op-release');
        (new ReleasePlanningReservation())($r);
        (new ReleasePlanningReservation())($r); // idempotente

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('USAGE_RESERVATION_ALREADY_RELEASED');
        (new ConsumePlanningReservation())($r);
    }

    public function test_human_review_reservation_rejected_when_plan_excludes_it(): void
    {
        $period = $this->activePeriod(['human_review_limit' => 0, 'human_review_required' => false]);
        $reserve = new ReservePlanningUnits();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('HUMAN_REVIEW_NOT_INCLUDED');
        $reserve($period, UsageResource::HumanReview, 1, 'op-hr');
    }

    public function test_human_review_reservation_succeeds_when_plan_includes_limit(): void
    {
        $period = $this->activePeriod([
            'human_review_limit' => 4, 'human_review_required' => true, 'correction_limit' => 2, 'group_limit' => 2,
        ]);
        $reserve = new ReservePlanningUnits();
        $r = $reserve($period, UsageResource::HumanReview, 2, 'op-hr');
        $this->assertSame(UsageReservationStatus::Reserved, $r->status);
        $this->assertSame(2, (new SubscriptionBalance())->forPeriod($period)['human_review']->reserved);
        $this->assertSame(2, (new SubscriptionBalance())->forPeriod($period)['human_review']->available());
    }

    public function test_reservation_on_ended_period_fails(): void
    {
        $period = $this->activePeriod();
        $period->forceFill(['status' => SubscriptionPeriodStatus::Ended->value])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_PERIOD_NOT_ACTIVE');
        (new ReservePlanningUnits())($period, UsageResource::Planning, 1, 'op-x');
    }
}
