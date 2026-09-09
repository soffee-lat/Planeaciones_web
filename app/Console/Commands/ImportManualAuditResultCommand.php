<?php

namespace App\Console\Commands;

use App\Actions\AI\ImportManualAuditResult;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class ImportManualAuditResultCommand extends Command
{
    protected $signature = 'ai:import-audit-result
        {execution : ID de AiExecution audit/manual}
        {file : Ruta al JSON AuditResultV1}
        {--provider= : Proveedor real; omitir si se desconoce}
        {--model= : Modelo real; omitir si se desconoce}
        {--actual-cost= : Costo real conocido; omitir si se desconoce}
        {--currency= : Moneda ISO del costo, requerida si se informa costo}';

    protected $description = 'Importa y valida un resultado manual de auditoría sin decidir todavía aprobación o corrección.';

    public function handle(ImportManualAuditResult $action): int
    {
        $executionId = (int) $this->argument('execution');
        if ($executionId < 1) {
            $this->error('AI_AUDIT_EXECUTION_ID_INVALID');
            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('AI_AUDIT_RESULT_FILE_UNREADABLE');
            return self::FAILURE;
        }

        try {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || array_is_list($decoded)) {
                $this->error('AI_AUDIT_RESULT_JSON_OBJECT_REQUIRED');
                return self::FAILURE;
            }

            $execution = AiExecution::query()->findOrFail($executionId);
            $result = $action->execute(
                $execution,
                $decoded,
                $this->stringOption('provider'),
                $this->stringOption('model'),
                $this->stringOption('actual-cost'),
                $this->stringOption('currency'),
            );

            $this->info(sprintf(
                'Auditoría importada: execution=%d passed=%s findings=%d',
                $executionId,
                $result->passed ? 'true' : 'false',
                count($result->findings),
            ));

            return self::SUCCESS;
        } catch (JsonException) {
            $this->error('AI_AUDIT_RESULT_JSON_INVALID');
            return self::FAILURE;
        } catch (AiPipelineException $e) {
            $this->error($e->errorCode . ($e->detail ? ':' . $e->detail : ''));
            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('AI_AUDIT_RESULT_IMPORT_FAILED');
            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        return is_string($value) ? $value : null;
    }
}
