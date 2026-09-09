<?php

namespace App\Console\Commands;

use App\Actions\AI\ProcessOutboxEvent;
use App\Models\OutboxEvent;
use Illuminate\Console\Command;
use Throwable;

class ProcessAiOutboxCommand extends Command
{
    protected $signature = 'ai:process-outbox {--limit=25 : Máximo de eventos pendientes a reclamar}';

    protected $description = 'Procesa eventos pendientes del outbox IA de forma idempotente y recuperable';

    public function handle(ProcessOutboxEvent $processor): int
    {
        $limit = max(1, min(500, (int) $this->option('limit')));
        $ids = OutboxEvent::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->where(function ($query) {
                $query->whereNull('lease_expires_at')->orWhere('lease_expires_at', '<=', now());
            })
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');

        $processed = 0;
        $failed = 0;
        foreach ($ids as $id) {
            $event = OutboxEvent::query()->find($id);
            if (! $event) {
                continue;
            }
            try {
                if ($processor->execute($event)) {
                    $processed++;
                }
            } catch (Throwable) {
                $failed++;
            }
        }

        $this->info("Procesados: {$processed}; fallidos recuperables: {$failed}.");

        return $failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
