<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\ActivatePaidOrder;
use App\Actions\Commerce\CreateOrder;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Commerce\RecordManualPayment;
use App\Enums\SubscriptionPeriodStatus;
use App\Enums\SubscriptionStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Models\Subscription;
use App\Services\Commerce\Gateway\ManualPaymentInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class ActivatePaidOrderTest extends PedagogyTestCase
{
    use RefreshDatabase;

    private function paidOrder(array $pvOverrides = [])
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(array_merge([
            'plan_id' => $plan->id, 'number' => 1, 'price_minor' => 12000, 'currency' => 'MXN',
            'interval_unit' => 'month', 'interval_count' => 1,
            'planning_limit' => 4, 'human_review_limit' => 0,
        ], $pvOverrides));
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $customer = $this->customer();
        $order = (new CreateOrder())($customer, $pv->fresh(), 'ord-' . uniqid());
        (new RecordManualPayment())($order, new ManualPaymentInput(
            amountMinor: 12000,
            currency: 'MXN',
            providerReference: 'REF-' . uniqid(),
            method: 'transfer',
            occurredAt: now(),
        ), $this->admin());
        return [$order->fresh(), $customer, $pv->fresh()];
    }

    public function test_activation_creates_subscription_and_first_period_from_paid_order(): void
    {
        [$order, $customer, $pv] = $this->paidOrder(['planning_limit' => 4, 'interval_unit' => 'month', 'interval_count' => 1]);

        $subscription = (new ActivatePaidOrder())($order);

        $this->assertSame(SubscriptionStatus::Active, $subscription->status);
        $this->assertSame($customer->id, $subscription->customer_id);
        $this->assertSame($pv->id, $subscription->plan_version_id);
        $this->assertSame($pv->plan_id, $subscription->plan_id);

        $period = $subscription->periods()->first();
        $this->assertNotNull($period);
        $this->assertSame(SubscriptionPeriodStatus::Active, $period->status);
        $this->assertSame(4, $period->entitlement('planning_limit'));
        $this->assertSame($order->id, $period->paid_order_id);

        // ventana [paid_at, paid_at + 1 mes)
        $expectedEnd = $order->paid_at->copy()->addMonth();
        $this->assertTrue($period->ends_at->equalTo($expectedEnd));

        $order->refresh();
        $this->assertSame($subscription->id, $order->activated_subscription_id);
        $this->assertSame($period->id, $order->subscription_period_id);
    }

    public function test_activation_is_idempotent_when_replayed(): void
    {
        [$order] = $this->paidOrder();
        $sub1 = (new ActivatePaidOrder())($order);
        $sub2 = (new ActivatePaidOrder())($order->fresh());
        $this->assertSame($sub1->id, $sub2->id);
        $this->assertSame(1, $sub1->periods()->count());
    }

    public function test_activation_of_unpaid_order_fails(): void
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id, 'price_minor' => 12000, 'currency' => 'MXN']);
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $order = (new CreateOrder())($this->customer(), $pv->fresh(), 'ord-unpaid');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ORDER_NOT_PAID');
        (new ActivatePaidOrder())($order);
    }

    public function test_customer_with_active_subscription_generates_controlled_conflict(): void
    {
        [$order1, $customer, $pv] = $this->paidOrder();
        (new ActivatePaidOrder())($order1);

        // Segunda Order del mismo cliente para otro PlanVersion.
        $plan2 = Plan::factory()->create();
        $pv2 = PlanVersion::factory()->create(['plan_id' => $plan2->id, 'price_minor' => 12000, 'currency' => 'MXN']);
        (new \App\Actions\Plans\PublishPlanVersion())($pv2, $this->admin());
        $order2 = (new CreateOrder())($customer, $pv2->fresh(), 'ord-second');
        (new RecordManualPayment())($order2, new ManualPaymentInput(
            amountMinor: 12000, currency: 'MXN', providerReference: 'REF-2', method: 'transfer', occurredAt: now(),
        ), $this->admin());

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('CUSTOMER_ALREADY_HAS_ACTIVE_SUBSCRIPTION');
        (new ActivatePaidOrder())($order2->fresh());

        // La Order sigue paid, sin activated_subscription_id.
        $order2->refresh();
        $this->assertNull($order2->activated_subscription_id);
    }

    public function test_publishing_a_later_plan_version_does_not_alter_activated_subscription(): void
    {
        [$order, $customer, $pv] = $this->paidOrder(['planning_limit' => 4]);
        $sub = (new ActivatePaidOrder())($order);

        $pvNew = PlanVersion::factory()->create(['plan_id' => $pv->plan_id, 'number' => 2, 'planning_limit' => 99, 'price_minor' => 99999, 'currency' => 'MXN']);
        (new \App\Actions\Plans\PublishPlanVersion())($pvNew, $this->admin());

        $sub->refresh();
        $this->assertSame($pv->id, $sub->plan_version_id);
        $this->assertSame(4, $sub->periods()->first()->entitlement('planning_limit'));
    }

    public function test_transactional_rollback_leaves_no_partial_state(): void
    {
        [$order] = $this->paidOrder();

        // Provoca fallo forzando el OpenSubscriptionPeriod a lanzar excepción.
        $fake = new class extends OpenSubscriptionPeriod {
            public function __invoke($subscription, $startsAt, $endsAt): \App\Models\SubscriptionPeriod
            {
                throw new RuntimeException('FORCED_FAILURE');
            }
        };
        $action = new ActivatePaidOrder(openSubscriptionPeriod: $fake);

        try {
            $action($order);
            $this->fail('Expected FORCED_FAILURE');
        } catch (RuntimeException $e) {
            $this->assertSame('FORCED_FAILURE', $e->getMessage());
        }

        $order->refresh();
        $this->assertNull($order->activated_subscription_id, 'Order no debe quedar marcada como aplicada tras rollback');
        $this->assertNull($order->subscription_period_id);
        $this->assertSame(0, Subscription::query()->where('customer_id', $order->customer_id)->count(), 'No debe quedar Subscription parcial');
    }
}
