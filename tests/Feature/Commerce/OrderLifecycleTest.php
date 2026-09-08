<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\CancelOrder;
use App\Actions\Commerce\CreateOrder;
use App\Enums\OrderStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class OrderLifecycleTest extends PedagogyTestCase
{
    use RefreshDatabase;

    private function publishedPlanVersion(array $overrides = []): PlanVersion
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(array_merge([
            'plan_id' => $plan->id, 'number' => 1, 'price_minor' => 12000, 'currency' => 'MXN',
        ], $overrides));
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        return $pv->fresh();
    }

    public function test_admin_can_create_order_freezing_price_and_currency(): void
    {
        $pv = $this->publishedPlanVersion(['price_minor' => 15000, 'currency' => 'MXN']);
        $customer = $this->customer();
        $admin = $this->admin();

        $order = (new CreateOrder())($customer, $pv, 'ord-key-1', 'Compra piloto', $admin);

        $this->assertSame(OrderStatus::Pending, $order->status);
        $this->assertSame(15000, (int) $order->total_minor);
        $this->assertSame('MXN', $order->currency);
        $this->assertSame($pv->id, $order->plan_version_id);
        $this->assertSame($pv->plan_id, $order->plan_id);
        $this->assertSame($customer->id, $order->customer_id);
        $this->assertSame($admin->id, $order->created_by);
    }

    public function test_order_price_is_not_recalculated_when_new_plan_version_published(): void
    {
        $oldPv = $this->publishedPlanVersion(['price_minor' => 12000]);
        $order = (new CreateOrder())($this->customer(), $oldPv, 'ord-frozen', null);

        // Publica una PlanVersion posterior más cara del mismo plan.
        $newPv = PlanVersion::factory()->create(['plan_id' => $oldPv->plan_id, 'number' => 2, 'price_minor' => 99000]);
        (new \App\Actions\Plans\PublishPlanVersion())($newPv, $this->admin());

        $order->refresh();
        $this->assertSame(12000, (int) $order->total_minor);
        $this->assertSame($oldPv->id, $order->plan_version_id);
    }

    public function test_draft_plan_version_is_rejected(): void
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id]); // borrador

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ORDER_PLAN_VERSION_NOT_PUBLISHED');
        (new CreateOrder())($this->customer(), $pv, (string) Str::uuid());
    }

    public function test_same_idempotency_key_returns_same_order(): void
    {
        $pv = $this->publishedPlanVersion();
        $customer = $this->customer();
        $o1 = (new CreateOrder())($customer, $pv, 'idem-1');
        $o2 = (new CreateOrder())($customer, $pv, 'idem-1');
        $this->assertSame($o1->id, $o2->id);
    }

    public function test_same_idempotency_key_different_payload_conflicts(): void
    {
        $pv1 = $this->publishedPlanVersion();
        $pv2 = $this->publishedPlanVersion();
        $customer = $this->customer();
        (new CreateOrder())($customer, $pv1, 'idem-conflict');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ORDER_IDEMPOTENCY_CONFLICT');
        (new CreateOrder())($customer, $pv2, 'idem-conflict');
    }

    public function test_cancel_pending_order_transitions_to_cancelled(): void
    {
        $pv = $this->publishedPlanVersion();
        $order = (new CreateOrder())($this->customer(), $pv, 'ord-cancel');
        $cancelled = (new CancelOrder())($order, $this->admin());
        $this->assertSame(OrderStatus::Cancelled, $cancelled->status);
    }

    public function test_cancel_of_paid_order_is_rejected_by_business_rule(): void
    {
        $pv = $this->publishedPlanVersion();
        $order = (new CreateOrder())($this->customer(), $pv, 'ord-noncancel');
        $order->forceFill(['status' => 'paid', 'paid_at' => now()])->save();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('ORDER_NOT_CANCELLABLE');
        (new CancelOrder())($order, $this->admin());
    }
}
