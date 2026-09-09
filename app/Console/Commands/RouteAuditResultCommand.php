<?php

namespace App\Console\Commands;

use App\Actions\AI\RouteAuditResult;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use Illuminate\Console\Command;
use Throwable;

class RouteAuditResultCommand extends Command
{
    protected $signature = 'ai:route-audit-result {execution : ID de AiExecution audit/succeeded}';

    protected $description = 'Decide auditoría aprobada, revisión humana o corrección interna sin consumir rondas del cliente.';

    public function handle(RouteAuditResult $action): int
    {
        $executionId = (int) $this->argument('execution');
        if ($executionId < 1) {
            $this->error('AI_AUDIT_EXECUTION_ID_INVALID');
            return self::FAILURE;
        }

        try {
            $execution = AiExecution::query()->findOrFail($executionId);
            $request = $action->execute($execution);
            $this->info(sprintf('Auditoría encaminada: request=%d status=%s', $request->id, $request->status->value));
            return self::SUCCESS;
        } catch (AiPipelineException $e) {
            $this->error($e->errorCode . ($e->detail ? ':' . $e->detail : ''));
            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('AI_AUDIT_ROUTING_FAILED');
            return self::FAILURE;
        }
    }
}
