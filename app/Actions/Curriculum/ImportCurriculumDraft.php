<?php

namespace App\Actions\Curriculum;

use App\Models\User;
use App\Services\Curriculum\CurriculumImportService;
use App\Support\Curriculum\ImportReport;
use RuntimeException;

/**
 * Orquestador de importación de borradores curriculares.
 * Único camino autorizado desde CLI o UI administrativa.
 */
class ImportCurriculumDraft
{
    public function __construct(private readonly CurriculumImportService $service) {}

    public function __invoke(string $path, User $actor, bool $dryRun = false): ImportReport
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("IMPORT_FILE_UNREADABLE: {$path}");
        }
        $raw = file_get_contents($path);
        if ($raw === false || $raw === '') {
            throw new RuntimeException('IMPORT_FILE_EMPTY');
        }
        $hash = hash('sha256', $raw);
        try {
            $payload = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $report = new ImportReport();
            $report->dryRun = $dryRun;
            $report->fileHash = $hash;
            $report->addError('INVALID_JSON', 'Archivo JSON inválido: ' . $e->getMessage());
            return $report;
        }
        if (! is_array($payload)) {
            $report = new ImportReport();
            $report->dryRun = $dryRun;
            $report->fileHash = $hash;
            $report->addError('INVALID_JSON', 'El JSON raíz debe ser un objeto.');
            return $report;
        }
        return $this->service->import($payload, $actor, $dryRun, $hash);
    }
}
