<?php

namespace App\Console\Commands;

use App\Actions\Notifications\QueueOperationalNotification;
use App\Enums\OperationalNotificationType;
use App\Enums\SubscriptionStatus;
use App\Models\Subscription;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class QueueUpcomingRenewalNotificationsCommand extends Command
{
    protected $signature = 'notifications:queue-renewals {--days=}';
    protected $description = 'Encola recordatorios deduplicados de renovaciones próximas';

    public function handle(QueueOperationalNotification $queue): int
    {
        $days = $this->option('days') !== null
            ? (int) $this->option('days')
            : (int) config('operational_notifications.renewal_days', 7);
        if ($days < 1 || $days > 30) {
            $this->error('La ventana debe estar entre 1 y 30 días.');
            return self::INVALID;
        }

        $now = now();
        $until = $now->copy()->addDays($days);
        $timezone = (string) config('operational_notifications.business_timezone', 'America/Mexico_City');
        $queued = 0;

        Subscription::query()
            ->with(['customer', 'plan'])
            ->whereIn('status', SubscriptionStatus::operationalValues())
            ->whereNull('cancel_requested_at')
            ->whereNotNull('renews_at')
            ->where('renews_at', '>', $now)
            ->where('renews_at', '<=', $until)
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('ends_at')->orWhere('ends_at', '>', $now);
            })
            ->orderBy('id')
            ->chunkById(100, function ($subscriptions) use ($queue, $timezone, &$queued): void {
                foreach ($subscriptions as $subscription) {
                    if (! $subscription->customer || ! $subscription->renews_at) {
                        continue;
                    }
                    $renewalKey = $subscription->renews_at->copy()->utc()->format('YmdHis');
                    $date = $subscription->renews_at->copy()->timezone($timezone)->format('d/m/Y');
                    $planName = trim((string) ($subscription->plan?->name ?? 'tu plan'));
                    $queue->execute(
                        OperationalNotificationType::SubscriptionRenewalUpcoming,
                        $subscription->customer,
                        "subscription:{$subscription->id}:renewal:{$renewalKey}",
                        'subscription',
                        (int) $subscription->id,
                        [
                            'title' => 'Tu plan se renovará pronto',
                            'body' => "{$planName} tiene renovación prevista para el {$date}.",
                            'url' => '/app/mi-plan',
                        ],
                    );
                    $queued++;
                }
            });

        $this->info("Renovaciones evaluadas/en cola: {$queued}");
        return self::SUCCESS;
    }
}
