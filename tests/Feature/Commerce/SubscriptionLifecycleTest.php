<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Enums\SubscriptionPeriodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class SubscriptionLifecycleTest extends PedagogyTestCase
{
    use RefreshDatabase;

    private function makePublishedPlanVersion(array $overrides = []): PlanVersion
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(array_merge(['plan_id' => $plan->id, 'number' => 1], $overrides));
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        return $pv->fresh();
    }

    public function test_admin_can_create_subscription_pointing_to_published_plan_version(): void
    {
        $pv = $this->makePublishedPlanVersion();
        $customer = $this->customer();

        $subscription = (new CreateSubscription())($customer, $pv, now());

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($pv->id, $subscription->plan_version_id);
        $this->assertSame($pv->plan_id, $subscription->plan_id);
        $this->assertSame($customer->id, $subscription->customer_id);
    }

    public function test_cannot_create_subscription_for_draft_plan_version(): void
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id]); // draft
        $customer = $this->customer();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_PLAN_VERSION_NOT_PUBLISHED');
        (new CreateSubscription())($customer, $pv, now());
    }

    public function test_partial_unique_index_prevents_second_operational_subscription(): void
    {
        $pv1 = $this->makePublishedPlanVersion();
        $pv2 = $this->makePublishedPlanVersion();
        $customer = $this->customer();

        (new CreateSubscription())($customer, $pv1, now());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_ALREADY_OPERATIONAL');
        (new CreateSubscription())($customer, $pv2, now());
    }

    public function test_cancelled_subscription_frees_slot_for_new_operational_subscription(): void
    {
        $pv1 = $this->makePublishedPlanVersion();
        $pv2 = $this->makePublishedPlanVersion();
        $customer = $this->customer();

        $s1 = (new CreateSubscription())($customer, $pv1, now());
        $s1->forceFill(['status' => SubscriptionStatus::Cancelled->value, 'ends_at' => now()])->save();

        $s2 = (new CreateSubscription())($customer, $pv2, now());
        $this->assertSame(SubscriptionStatus::Active, $s2->status);
    }

    public function test_open_period_freezes_entitlement_snapshot_from_current_plan_version(): void
    {
        $pv = $this->makePublishedPlanVersion([
            'planning_limit' => 4,
            'human_review_limit' => 2,
            'max_planning_days' => 7,
        ]);
        $customer = $this->customer();
        $sub = (new CreateSubscription())($customer, $pv, now());

        $period = (new OpenSubscriptionPeriod())($sub, now()->subDay(), now()->addDays(30));

        $this->assertSame(SubscriptionPeriodStatus::Active, $period->status);
        $this->assertSame(4, $period->entitlement('planning_limit'));
        $this->assertSame(2, $period->entitlement('human_review_limit'));
        $this->assertSame(7, $period->entitlement('max_planning_days'));
        $this->assertSame($pv->id, $period->entitlement_snapshot['plan_version_id']);
    }

    public function test_period_snapshot_is_not_affected_by_later_plan_version_publication(): void
    {
        $pvOld = $this->makePublishedPlanVersion(['planning_limit' => 4]);
        $customer = $this->customer();
        $sub = (new CreateSubscription())($customer, $pvOld, now());
        $period = (new OpenSubscriptionPeriod())($sub, now()->subDay(), now()->addDays(30));

        // Publica nueva versión del mismo plan con distintos límites.
        $pvNew = PlanVersion::factory()->create(['plan_id' => $pvOld->plan_id, 'number' => 2, 'planning_limit' => 10]);
        (new \App\Actions\Plans\PublishPlanVersion())($pvNew, $this->admin());

        $period->refresh();
        $this->assertSame(4, $period->entitlement('planning_limit'));
    }

    public function test_overlapping_periods_are_rejected_by_exclusion_constraint(): void
    {
        $pv = $this->makePublishedPlanVersion();
        $customer = $this->customer();
        $sub = (new CreateSubscription())($customer, $pv, now());
        (new OpenSubscriptionPeriod())($sub, now()->subDays(1), now()->addDays(30));

        $this->expectException(QueryException::class);
        (new OpenSubscriptionPeriod())($sub, now()->addDays(5), now()->addDays(40));
    }

    public function test_period_with_invalid_range_is_rejected(): void
    {
        $pv = $this->makePublishedPlanVersion();
        $customer = $this->customer();
        $sub = (new CreateSubscription())($customer, $pv, now());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('SUBSCRIPTION_PERIOD_INVALID_RANGE');
        (new OpenSubscriptionPeriod())($sub, now()->addDays(10), now()->addDays(5));
    }
}
