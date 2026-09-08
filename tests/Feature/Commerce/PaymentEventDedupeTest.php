<?php

namespace Tests\Feature\Commerce;

use App\Actions\Commerce\CreateOrder;
use App\Actions\Commerce\RecordManualPayment;
use App\Enums\PaymentEventStatus;
use App\Models\PaymentEvent;
use App\Models\Plan;
use App\Models\PlanVersion;
use App\Services\Commerce\Gateway\ManualPaymentInput;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\PedagogyTestCase;

class PaymentEventDedupeTest extends PedagogyTestCase
{
    use RefreshDatabase;

    public function test_unique_provider_event_id_prevents_duplicate_ingestion(): void
    {
        $payload = json_encode(['ref' => 'X-1']);
        PaymentEvent::factory()->create([
            'provider' => 'manual',
            'event_id' => 'evt-unique-1',
            'payload_hash' => hash('sha256', $payload),
            'status' => PaymentEventStatus::Received->value,
        ]);

        $this->expectException(QueryException::class);
        PaymentEvent::factory()->create([
            'provider' => 'manual',
            'event_id' => 'evt-unique-1',
            'payload_hash' => hash('sha256', $payload),
            'status' => PaymentEventStatus::Received->value,
        ]);
    }

    public function test_processed_at_consistency_check_blocks_processed_without_timestamp(): void
    {
        $this->expectException(QueryException::class);
        PaymentEvent::factory()->create([
            'status' => PaymentEventStatus::Processed->value,
            'processed_at' => null,
        ]);
    }

    public function test_full_flow_manual_payment_records_event_stream_conceptually(): void
    {
        // Este test documenta la relación: en la ingesta manual creamos un
        // Payment vía RecordManualPayment y —en la línea de gateways futuros—
        // un webhook simulado quedaría como PaymentEvent con provider="manual"
        // y event_id = provider_reference. Aquí verificamos que ambas tablas
        // pueden coexistir referenciándose sin choques.
        $plan = Plan::factory()->create();
        $pv = PlanVersion::factory()->create(['plan_id' => $plan->id, 'price_minor' => 12000, 'currency' => 'MXN']);
        (new \App\Actions\Plans\PublishPlanVersion())($pv, $this->admin());
        $order = (new CreateOrder())($this->customer(), $pv->fresh(), 'ord-evt');
        $payment = (new RecordManualPayment())($order, new ManualPaymentInput(
            amountMinor: 12000, currency: 'MXN', providerReference: 'REF-EVT-1', method: 'transfer', occurredAt: now(),
        ), $this->admin());

        $event = PaymentEvent::factory()->create([
            'provider' => 'manual',
            'event_id' => 'REF-EVT-1',
            'payment_id' => $payment->id,
            'order_id' => $order->id,
            'status' => PaymentEventStatus::Processed->value,
            'processed_at' => now(),
        ]);
        $this->assertSame($payment->id, $event->payment_id);
        $this->assertSame($order->id, $event->order_id);
    }
}
