<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\ActivatePaidOrder;
use App\Actions\Commerce\CreateOrder;
use App\Actions\Commerce\RecordManualPayment;
use App\Actions\Commerce\RecordManualRefund;
use App\Enums\OrderStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Commerce\Gateway\ManualPaymentInput;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class RefundTest extends PedagogyTestCase
{
    use RefreshDatabase;

    private function paidPayment(int $amount = 12000)
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id, 'price_minor' => $amount, 'currency' => 'MXN']);
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $order = (new CreateOrder())($this->customer(), $pv->fresh(), 'ord-' . uniqid());
        $payment = (new RecordManualPayment())($order, new ManualPaymentInput(
            amountMinor: $amount, currency: 'MXN', providerReference: 'REF-' . uniqid(),
            method: 'transfer', occurredAt: now(),
        ), $this->admin());
        return [$order->fresh(), $payment];
    }

    public function test_full_refund_marks_order_refunded(): void
    {
        [$order, $payment] = $this->paidPayment(12000);
        (new RecordManualRefund())($payment, 12000, 'Cliente insatisfecho', 'refund-full-1', $this->admin());
        $order->refresh();
        $this->assertSame(OrderStatus::Refunded, $order->status);
    }

    public function test_partial_refund_keeps_order_paid(): void
    {
        [$order, $payment] = $this->paidPayment(12000);
        (new RecordManualRefund())($payment, 3000, 'Ajuste parcial', 'refund-part-1', $this->admin());
        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
    }

    public function test_sum_of_refunds_cannot_exceed_payment(): void
    {
        [, $payment] = $this->paidPayment(12000);
        (new RecordManualRefund())($payment, 8000, 'r1', 'refund-a', $this->admin());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUND_EXCEEDS_PAYMENT');
        (new RecordManualRefund())($payment, 5000, 'r2', 'refund-b', $this->admin());
    }

    public function test_refund_is_idempotent_with_same_key_and_payload(): void
    {
        [, $payment] = $this->paidPayment(12000);
        $r1 = (new RecordManualRefund())($payment, 3000, 'motivo', 'refund-idem', $this->admin());
        $r2 = (new RecordManualRefund())($payment, 3000, 'motivo', 'refund-idem', $this->admin());
        $this->assertSame($r1->id, $r2->id);
    }

    public function test_refund_key_conflict_with_different_payload_fails(): void
    {
        [, $payment] = $this->paidPayment(12000);
        (new RecordManualRefund())($payment, 3000, 'motivo', 'refund-cfl', $this->admin());
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('REFUND_IDEMPOTENCY_CONFLICT');
        (new RecordManualRefund())($payment, 4000, 'motivo', 'refund-cfl', $this->admin());
    }

    public function test_refund_does_not_release_consumed_usage_reservations(): void
    {
        // Verbatim brief: un Refund NUNCA devuelve unidades automáticamente.
        [$order, $payment] = $this->paidPayment(12000);
        $sub = (new ActivatePaidOrder())($order->fresh());
        $period = $sub->periods()->first();
        $r = (new \App\Actions\Commerce\ReservePlanningUnits())(
            $period, \App\Enums\UsageResource::Planning, 2, 'op-usage-1'
        );
        (new \App\Actions\Commerce\ConsumePlanningReservation())($r);

        // Refund total.
        (new RecordManualRefund())($payment, 12000, 'devolucion total', 'refund-final', $this->admin());

        // La reserva sigue consumida; balance no cambia.
        $r->refresh();
        $this->assertSame(\App\Enums\UsageReservationStatus::Consumed, $r->status);
        $balance = (new \App\Services\Commerce\SubscriptionBalance())->forPeriod($period->fresh())['planning'];
        $this->assertSame(2, $balance->consumed);
    }
}
