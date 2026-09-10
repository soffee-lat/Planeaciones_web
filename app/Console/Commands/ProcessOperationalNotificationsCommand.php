<?php

namespace App\Console\Commands;

use App\Actions\Notifications\ProcessOperationalNotificationEvent;
use App\Models\OperationalNotificationEvent;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

class ProcessOperationalNotificationsCommand extends Command
{
    protected $signature = 'notifications:process-operational {--limit=50}';
    protected $description = 'Materializa notificaciones internas y procesa emails operativos pendientes';

    public function handle(ProcessOperationalNotificationEvent $process): int
    {
        $limit = (int) $this->option('limit');
        if ($limit < 1 || $limit > 500) {
            $this->error('El límite debe estar entre 1 y 500.');
            return self::INVALID;
        }

        $now = now();
        $ids = OperationalNotificationEvent::query()
            ->where(function (Builder $query) use ($now): void {
                $query->whereNull('internal_sent_at')
                    ->orWhere(function (Builder $email) use ($now): void {
                        $email->whereNull('email_sent_at')
                            ->where('email_available_at', '<=', $now)
                            ->where(function (Builder $lease) use ($now): void {
                                $lease->whereNull('email_lease_expires_at')->orWhere('email_lease_expires_at', '<=', $now);
                            });
                    });
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        foreach ($ids as $id) {
            $process->execute((int) $id);
        }

        $this->info('Procesadas: ' . $ids->count());
        return self::SUCCESS;
    }
}
