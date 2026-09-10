<?php

namespace App\Services\Notifications;

use App\Models\OperationalNotificationEvent;
use App\Notifications\OperationalMailNotification;
use RuntimeException;

class OperationalEmailSender
{
    public function send(OperationalNotificationEvent $event): void
    {
        $event->loadMissing('recipient');
        $recipient = $event->recipient;
        if (! $recipient || trim((string) $recipient->email) === '') {
            throw new RuntimeException('OPERATIONAL_NOTIFICATION_RECIPIENT_MISSING');
        }

        $recipient->notifyNow(new OperationalMailNotification($event->payload), ['mail']);
    }
}
