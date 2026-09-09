<?php

namespace App\Console\Commands;

use App\Actions\AI\ImportManualCorrectionResult;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class ImportManualCorrectionResultCommand extends Command
{
    protected $signature = 'ai:import-correction-result
        {execution : ID de AiExecution correction/manual}
        {file : Ruta al JSON CorrectionResultV1}
        {--provider= : Proveedor real; omitir si se desconoce}
        {--model= : Modelo real; omitir si se desconoce}
        {--actual-cost= : Costo real conocido; omitir si se desconoce}
        {--currency= : Moneda ISO del costo, requerida si se informa costo}';

    protected $description = 'Importa una corrección interna acotada, crea versión hija y prepara reauditoría.';

    public function handle(ImportManualCorrectionResult $action): int
    {
        $executionId = (int) $this->argument('execution');
        if ($executionId < 1) {
            $this->error('AI_CORRECTION_EXECUTION_ID_INVALID');
            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('AI_CORRECTION_RESULT_FILE_UNREADABLE');
            return self::FAILURE;
        }

        try {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || array_is_list($decoded)) {
                $this->error('AI_CORRECTION_RESULT_JSON_OBJECT_REQUIRED');
                return self::FAILURE;
            }

            $execution = AiExecution::query()->findOrFail($executionId);
            $version = $action->execute(
                $execution,
                $decoded,
                $this->stringOption('provider'),
                $this->stringOption('model'),
                $this->stringOption('actual-cost'),
                $this->stringOption('currency'),
            );
            $this->info(sprintf('Corrección importada: document_version=%d request=%d', $version->id, $version->document->request_id));
            return self::SUCCESS;
        } catch (JsonException) {
            $this->error('AI_CORRECTION_RESULT_JSON_INVALID');
            return self::FAILURE;
        } catch (AiPipelineException $e) {
            $this->error($e->errorCode . ($e->detail ? ':' . $e->detail : ''));
            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('AI_CORRECTION_RESULT_IMPORT_FAILED');
            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        return is_string($value) ? $value : null;
    }
}
