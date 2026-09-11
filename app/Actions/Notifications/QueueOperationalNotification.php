<?php

namespace App\Actions\Notifications;

use App\Enums\OperationalNotificationType;
use App\Models\OperationalNotificationEvent;
use App\Models\User;
use App\Support\AI\CanonicalJson;
use RuntimeException;

final class QueueOperationalNotification
{
    /** @param array<string,mixed> $payload */
    public function execute(
        OperationalNotificationType $type,
        User $recipient,
        string $eventKey,
        string $aggregateType,
        int $aggregateId,
        array $payload,
    ): OperationalNotificationEvent {
        $eventKey = trim($eventKey);
        $aggregateType = trim($aggregateType);
        $title = trim((string) ($payload['title'] ?? ''));
        $body = trim((string) ($payload['body'] ?? ''));
        $url = $payload['url'] ?? null;

        if ($eventKey === '' || mb_strlen($eventKey) > 255
            || $aggregateType === '' || mb_strlen($aggregateType) > 64
            || $aggregateId <= 0 || $title === '' || $body === ''
            || ($url !== null && (! is_string($url) || ! str_starts_with($url, '/')))) {
            throw new RuntimeException('OPERATIONAL_NOTIFICATION_PAYLOAD_INVALID');
        }

        $normalized = [
            'title' => $title,
            'body' => $body,
            'url' => $url,
        ];

        $event = OperationalNotificationEvent::query()->firstOrCreate(
            ['event_key' => $eventKey],
            [
                'type' => $type->value,
                'recipient_id' => $recipient->id,
                'aggregate_type' => $aggregateType,
                'aggregate_id' => $aggregateId,
                'payload' => $normalized,
                'internal_sent_at' => null,
                'email_sent_at' => null,
                'email_attempts' => 0,
                'email_available_at' => now(),
                'email_claimed_at' => null,
                'email_lease_expires_at' => null,
                'email_last_error_code' => null,
            ],
        );

        if ($event->type !== $type
            || (int) $event->recipient_id !== (int) $recipient->id
            || $event->aggregate_type !== $aggregateType
            || (int) $event->aggregate_id !== $aggregateId
            || CanonicalJson::hash($event->payload) !== CanonicalJson::hash($normalized)) {
            throw new RuntimeException('OPERATIONAL_NOTIFICATION_IDEMPOTENCY_CONFLICT');
        }

        return $event;
    }
}
