<?php

namespace App\Console\Commands;

use App\Actions\AI\ImportManualGenerationResult;
use App\Exceptions\AiContractException;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use Illuminate\Console\Command;
use JsonException;
use Throwable;

class ImportManualGenerationResultCommand extends Command
{
    protected $signature = 'ai:import-generation-result
        {execution : ID de AiExecution generation/manual}
        {file : Ruta al JSON GeneratedPlanDraftV1}
        {--provider= : Proveedor real; omitir si se desconoce}
        {--model= : Modelo real; omitir si se desconoce}
        {--actual-cost= : Costo real conocido; omitir si se desconoce}
        {--currency= : Moneda ISO del costo, requerida si se informa costo}';

    protected $description = 'Importa un resultado manual de generación, crea DocumentVersion y prepara AUDITORIA_IA.';

    public function handle(ImportManualGenerationResult $action): int
    {
        $executionId = (int) $this->argument('execution');
        if ($executionId < 1) {
            $this->error('AI_GENERATION_EXECUTION_ID_INVALID');
            return self::FAILURE;
        }

        $path = (string) $this->argument('file');
        if (! is_file($path) || ! is_readable($path)) {
            $this->error('AI_GENERATION_RESULT_FILE_UNREADABLE');
            return self::FAILURE;
        }

        try {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || array_is_list($decoded)) {
                $this->error('AI_GENERATION_RESULT_JSON_OBJECT_REQUIRED');
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

            $this->info(sprintf(
                'Resultado importado: document_version=%d document=%d request=%d',
                $version->id,
                $version->document_id,
                $version->document->request_id,
            ));

            return self::SUCCESS;
        } catch (JsonException) {
            $this->error('AI_GENERATION_RESULT_JSON_INVALID');
            return self::FAILURE;
        } catch (AiContractException $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        } catch (AiPipelineException $e) {
            $this->error($e->errorCode . ($e->detail ? ':' . $e->detail : ''));
            return self::FAILURE;
        } catch (Throwable $e) {
            report($e);
            $this->error('AI_GENERATION_RESULT_IMPORT_FAILED');
            return self::FAILURE;
        }
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);
        return is_string($value) ? $value : null;
    }
}
