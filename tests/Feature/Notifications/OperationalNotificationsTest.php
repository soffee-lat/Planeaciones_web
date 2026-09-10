<?php

namespace Tests\Feature\Notifications;

use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Notifications\ProcessOperationalNotificationEvent;
use App\Actions\Notifications\QueueOperationalNotification;
use App\Actions\Review\AssignReviewer;
use App\Enums\OperationalNotificationType;
use App\Enums\SubscriptionStatus;
use App\Models\OperationalNotificationEvent;
use App\Models\Subscription;
use App\Services\Notifications\OperationalEmailSender;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesRenderedPlanningScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class OperationalNotificationsTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesRenderedPlanningScenario;
    use CreatesReviewerScenario;

    public function test_entrega_publicada_encola_aviso_del_cliente_una_sola_vez(): void
    {
        $scene = $this->renderedPlanningScene('notifications/delivery');

        $delivery = app(PublishPlanningDelivery::class)->execute($scene['request']);
        app(PublishPlanningDelivery::class)->execute($scene['request']->fresh());

        $event = OperationalNotificationEvent::query()->sole();
        $this->assertSame(OperationalNotificationType::PlanningDelivered, $event->type);
        $this->assertSame($scene['request']->owner_id, $event->recipient_id);
        $this->assertSame($delivery->id, $event->aggregate_id);
        $this->assertSame('planning_delivery', $event->aggregate_type);
        $this->assertSame('/app/planning-requests/' . $scene['request']->id, $event->payload['url']);
    }

    public function test_asignacion_de_revisor_encola_aviso_para_el_revisor(): void
    {
        $scene = $this->humanReviewReadyRequest();
        $profile = $this->reviewerForRequest($scene['request']);

        $assignment = app(AssignReviewer::class)->execute($scene['request']);

        $this->assertNotNull($assignment);
        $event = OperationalNotificationEvent::query()->sole();
        $this->assertSame(OperationalNotificationType::ReviewAssigned, $event->type);
        $this->assertSame($profile->user_id, $event->recipient_id);
        $this->assertSame('/review/assignments/' . $assignment->id, $event->payload['url']);
    }

    public function test_procesamiento_materializa_aviso_interno_y_email_sin_duplicar(): void
    {
        config()->set('mail.default', 'array');
        $user = $this->customer();
        $event = app(QueueOperationalNotification::class)->execute(
            OperationalNotificationType::SubscriptionRenewalUpcoming,
            $user,
            'test:notification:success',
            'subscription',
            123,
            [
                'title' => 'Renovación próxima',
                'body' => 'Tu plan se renovará pronto.',
                'url' => '/app/mi-plan',
            ],
        );

        $first = app(ProcessOperationalNotificationEvent::class)->execute($event);
        $second = app(ProcessOperationalNotificationEvent::class)->execute($event->fresh());

        $this->assertNotNull($first->internal_sent_at);
        $this->assertNotNull($first->email_sent_at);
        $this->assertSame(1, $first->email_attempts);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
        // Reproduce la consulta usada por Filament DatabaseNotifications.
        $this->assertSame(1, DB::table('notifications')
            ->where('notifiable_id', $user->id)
            ->whereRaw("data->>'format' = ?", ['filament'])
            ->count());
        $this->assertSame($first->email_sent_at?->toISOString(), $second->email_sent_at?->toISOString());
        $this->assertSame(1, $second->email_attempts);
    }

    public function test_email_fallido_conserva_aviso_interno_y_se_puede_reintentar(): void
    {
        config()->set('mail.default', 'array');
        config()->set('operational_notifications.email_retry_seconds', 30);
        $user = $this->customer();
        $event = app(QueueOperationalNotification::class)->execute(
            OperationalNotificationType::SubscriptionRenewalUpcoming,
            $user,
            'test:notification:retry',
            'subscription',
            124,
            [
                'title' => 'Renovación próxima',
                'body' => 'Tu plan se renovará pronto.',
                'url' => '/app/mi-plan',
            ],
        );

        app()->instance(OperationalEmailSender::class, new class extends OperationalEmailSender {
            public function send(OperationalNotificationEvent $event): void
            {
                throw new RuntimeException('MAIL_TRANSPORT_DOWN');
            }
        });

        $failed = app(ProcessOperationalNotificationEvent::class)->execute($event);
        $this->assertNotNull($failed->internal_sent_at);
        $this->assertNull($failed->email_sent_at);
        $this->assertSame(1, $failed->email_attempts);
        $this->assertNotNull($failed->email_last_error_code);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());

        $this->travel(31)->seconds();
        app()->forgetInstance(OperationalEmailSender::class);
        $recovered = app(ProcessOperationalNotificationEvent::class)->execute($event->fresh());

        $this->assertNotNull($recovered->email_sent_at);
        $this->assertSame(2, $recovered->email_attempts);
        $this->assertNull($recovered->email_last_error_code);
        $this->assertSame(1, DB::table('notifications')->where('notifiable_id', $user->id)->count());
    }

    public function test_recordatorio_de_renovacion_es_deduplicado_por_fecha_de_renovacion(): void
    {
        $customer = $this->customer();
        $subscription = Subscription::factory()->create([
            'customer_id' => $customer->id,
            'status' => SubscriptionStatus::Active->value,
            'starts_at' => now()->subMonth(),
            'renews_at' => now()->addDays(5),
            'cancel_requested_at' => null,
            'ends_at' => null,
        ]);

        $this->artisan('notifications:queue-renewals')->assertSuccessful();
        $this->artisan('notifications:queue-renewals')->assertSuccessful();

        $event = OperationalNotificationEvent::query()->sole();
        $this->assertSame($subscription->id, $event->aggregate_id);
        $this->assertSame($customer->id, $event->recipient_id);
        $this->assertSame(OperationalNotificationType::SubscriptionRenewalUpcoming, $event->type);
    }

    public function test_historial_operativo_no_permite_mutar_identidad_ni_borrar(): void
    {
        $user = $this->customer();
        $event = app(QueueOperationalNotification::class)->execute(
            OperationalNotificationType::SubscriptionRenewalUpcoming,
            $user,
            'test:notification:immutable',
            'subscription',
            125,
            ['title' => 'Aviso', 'body' => 'Contenido del aviso.', 'url' => '/app/mi-plan'],
        );

        try {
            DB::table('operational_notification_events')->where('id', $event->id)->update(['event_key' => 'changed']);
            $this->fail('PostgreSQL debe impedir cambiar la identidad del evento.');
        } catch (QueryException $error) {
            $this->assertStringContainsString('OPERATIONAL_NOTIFICATION_IDENTITY_IMMUTABLE', $error->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('operational_notification_events')->where('id', $event->id)->delete();
    }
}
