<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\CreateOrder;
use App\Actions\Commerce\RecordManualPayment;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Commerce\Gateway\ManualPaymentInput;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Feature\PedagogyTestCase;

class ManualPaymentTest extends PedagogyTestCase
{
    use RefreshDatabase;

    private function orderFor(int $priceMinor = 12000, string $currency = 'MXN'): array
    {
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create([
            'plan_id' => $plan->id, 'number' => 1, 'price_minor' => $priceMinor, 'currency' => $currency,
        ]);
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $customer = $this->customer();
        $order = (new CreateOrder())($customer, $pv->fresh(), 'ord-' . uniqid());
        return [$order, $customer];
    }

    private function input(int $amount, string $currency = 'MXN', ?string $ref = null): ManualPaymentInput
    {
        return new ManualPaymentInput(
            amountMinor: $amount,
            currency: $currency,
            providerReference: $ref ?? ('REF-' . uniqid()),
            method: 'transfer',
            occurredAt: now(),
        );
    }

    public function test_admin_records_manual_payment_marking_order_paid(): void
    {
        [$order] = $this->orderFor(12000, 'MXN');
        $admin = $this->admin();

        $payment = (new RecordManualPayment())($order, $this->input(12000, 'MXN', 'REF-1'), $admin);

        $this->assertSame(PaymentStatus::Succeeded, $payment->status);
        $this->assertSame(12000, (int) $payment->amount_minor);
        $this->assertSame('MXN', $payment->currency);
        $this->assertSame('REF-1', $payment->provider_reference);
        $this->assertSame($admin->id, $payment->confirmed_by);

        $order->refresh();
        $this->assertSame(OrderStatus::Paid, $order->status);
        $this->assertNotNull($order->paid_at);
    }

    public function test_amount_mismatch_is_rejected(): void
    {
        [$order] = $this->orderFor(12000);
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYMENT_AMOUNT_MISMATCH');
        (new RecordManualPayment())($order, $this->input(11999), $this->admin());
    }

    public function test_currency_mismatch_is_rejected(): void
    {
        [$order] = $this->orderFor(12000, 'MXN');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYMENT_CURRENCY_MISMATCH');
        (new RecordManualPayment())($order, $this->input(12000, 'USD'), $this->admin());
    }

    public function test_same_reference_returns_same_payment_idempotently(): void
    {
        [$order] = $this->orderFor(12000);
        $admin = $this->admin();
        $input = $this->input(12000, 'MXN', 'REF-IDEM');

        $p1 = (new RecordManualPayment())($order, $input, $admin);
        $p2 = (new RecordManualPayment())($order, $input, $admin);
        $this->assertSame($p1->id, $p2->id);
    }

    public function test_same_reference_different_amount_conflicts(): void
    {
        [$order1] = $this->orderFor(12000);
        [$order2] = $this->orderFor(12000);
        $admin = $this->admin();

        (new RecordManualPayment())($order1, $this->input(12000, 'MXN', 'REF-CFL'), $admin);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PAYMENT_IDEMPOTENCY_CONFLICT');
        (new RecordManualPayment())($order2, $this->input(12000, 'MXN', 'REF-CFL'), $admin);
    }

    public function test_cannot_pay_the_same_order_twice(): void
    {
        [$order] = $this->orderFor(12000);
        $admin = $this->admin();
        (new RecordManualPayment())($order, $this->input(12000, 'MXN', 'REF-1'), $admin);

        // El unique index parcial `payments_order_succeeded_uniq` bloquea un
        // segundo succeeded para la misma Order aunque venga con otra referencia.
        $this->expectException(QueryException::class);
        (new RecordManualPayment())($order, $this->input(12000, 'MXN', 'REF-2'), $admin);
    }
}
