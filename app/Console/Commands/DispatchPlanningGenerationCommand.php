<?php

namespace App\Console\Commands;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ProcessOutboxEvent;
use App\Exceptions\AiPipelineException;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use Illuminate\Console\Command;
use Throwable;

class DispatchPlanningGenerationCommand extends Command
{
    protected $signature = 'ai:dispatch-generation {request_id : ID de PlanningRequest} {--process-outbox : Preparar inmediatamente el paquete manual}';

    protected $description = 'Inicia idempotentemente la generación manual de una solicitud lista para procesar';

    public function handle(DispatchPlanningGeneration $dispatch, ProcessOutboxEvent $processor): int
    {
        $request = PlanningRequest::query()->find((int) $this->argument('request_id'));
        if (! $request) {
            $this->error('PLANNING_REQUEST_NOT_FOUND');
            return self::FAILURE;
        }

        try {
            $execution = $dispatch->execute($request);
            $this->info("AiExecution #{$execution->id}: {$execution->status->value}");

            if ($this->option('process-outbox')) {
                $package = $execution->fresh()->manualPackage;
                if (! $package) {
                    $event = OutboxEvent::query()
                        ->where('event_key', 'ai-execution:' . $execution->id . ':generation-dispatch')
                        ->whereNull('published_at')
                        ->first();

                    if ($event && $processor->execute($event)) {
                        $package = $execution->fresh()->manualPackage;
                    } elseif ($event) {
                        $this->warn('El evento está reclamado por otro proceso o todavía no está disponible.');
                    }
                }

                if ($package) {
                    $this->info('Paquete manual preparado.');
                    $this->line('Ubicación privada: ' . $package->disk . ':' . $package->path);
                } else {
                    $this->warn('Paquete manual aún pendiente.');
                }
            }

            return self::SUCCESS;
        } catch (AiPipelineException $e) {
            $this->error($e->errorCode);
            return self::FAILURE;
        } catch (Throwable) {
            $this->error('AI_GENERATION_DISPATCH_FAILED');
            return self::FAILURE;
        }
    }
}
