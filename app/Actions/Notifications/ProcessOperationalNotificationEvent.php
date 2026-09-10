<?php

namespace App\Actions\Notifications;

use App\Models\OperationalNotificationEvent;
use App\Services\Notifications\OperationalEmailSender;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessOperationalNotificationEvent
{
    public function __construct(private OperationalEmailSender $email) {}

    public function execute(OperationalNotificationEvent|int $event): OperationalNotificationEvent
    {
        $eventId = $event instanceof OperationalNotificationEvent ? $event->id : $event;

        DB::transaction(function () use ($eventId): void {
            $locked = OperationalNotificationEvent::query()->with('recipient')->whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($locked->internal_sent_at !== null) {
                return;
            }

            FilamentNotification::make()
                ->title((string) $locked->payload['title'])
                ->body((string) $locked->payload['body'])
                ->sendToDatabase($locked->recipient);

            $locked->forceFill(['internal_sent_at' => now()])->save();
        }, attempts: 3);

        $claimed = DB::transaction(function () use ($eventId): ?OperationalNotificationEvent {
            $locked = OperationalNotificationEvent::query()->with('recipient')->whereKey($eventId)->lockForUpdate()->firstOrFail();
            $now = now();
            if ($locked->email_sent_at !== null
                || $locked->email_available_at?->gt($now)
                || ($locked->email_lease_expires_at !== null && $locked->email_lease_expires_at->gt($now))) {
                return null;
            }

            $leaseSeconds = max(30, (int) config('operational_notifications.email_lease_seconds', 120));
            $locked->forceFill([
                'email_attempts' => (int) $locked->email_attempts + 1,
                'email_claimed_at' => $now,
                'email_lease_expires_at' => $now->copy()->addSeconds($leaseSeconds),
                'email_last_error_code' => null,
            ])->save();

            return $locked->fresh('recipient');
        }, attempts: 3);

        if (! $claimed) {
            return OperationalNotificationEvent::query()->findOrFail($eventId);
        }

        try {
            $this->email->send($claimed);
        } catch (Throwable $error) {
            report($error);
            DB::transaction(function () use ($eventId, $error): void {
                $locked = OperationalNotificationEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
                if ($locked->email_sent_at !== null) {
                    return;
                }
                $base = max(30, (int) config('operational_notifications.email_retry_seconds', 60));
                $delay = min(3600, $base * (2 ** min(5, max(0, (int) $locked->email_attempts - 1))));
                $locked->forceFill([
                    'email_available_at' => now()->addSeconds($delay),
                    'email_claimed_at' => null,
                    'email_lease_expires_at' => null,
                    'email_last_error_code' => mb_substr(class_basename($error), 0, 128),
                ])->save();
            }, attempts: 3);

            return OperationalNotificationEvent::query()->findOrFail($eventId);
        }

        DB::transaction(function () use ($eventId): void {
            $locked = OperationalNotificationEvent::query()->whereKey($eventId)->lockForUpdate()->firstOrFail();
            if ($locked->email_sent_at === null) {
                $locked->forceFill([
                    'email_sent_at' => now(),
                    'email_claimed_at' => null,
                    'email_lease_expires_at' => null,
                    'email_last_error_code' => null,
                ])->save();
            }
        }, attempts: 3);

        return OperationalNotificationEvent::query()->findOrFail($eventId);
    }
}
