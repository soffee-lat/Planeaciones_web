<?php

namespace App\Console\Commands;

use App\Actions\Curriculum\ImportCurriculumDraft;
use App\Enums\RoleCode;
use App\Models\User;
use App\Support\Curriculum\ImportReport;
use Illuminate\Console\Command;

class ImportCurriculumCommand extends Command
{
    protected $signature = 'curriculum:import
        {file : Ruta al archivo JSON con el catálogo estructurado}
        {--actor= : Email o ID del administrador responsable}
        {--dry-run : Validar y reportar sin escribir}';

    protected $description = 'Importa un catálogo curricular estructurado como borrador. Nunca publica.';

    public function handle(ImportCurriculumDraft $importer): int
    {
        $file = (string) $this->argument('file');
        $actorRef = (string) ($this->option('actor') ?? '');
        $dryRun = (bool) $this->option('dry-run');

        if ($actorRef === '') {
            $this->error('Debe indicarse --actor=<email|id> de un administrador activo.');
            return self::FAILURE;
        }

        $actor = ctype_digit($actorRef)
            ? User::find((int) $actorRef)
            : User::where('email', mb_strtolower($actorRef))->first();

        if (! $actor) {
            $this->error("Actor no encontrado: {$actorRef}");
            return self::FAILURE;
        }
        if (! $actor->hasRole(RoleCode::Administrator) || $actor->status !== 'active') {
            $this->error("El actor {$actor->email} no es administrador activo.");
            return self::FAILURE;
        }

        try {
            $report = $importer($file, $actor, $dryRun);
        } catch (\Throwable $e) {
            $this->error($e->getMessage());
            return self::FAILURE;
        }

        $this->renderReport($report);

        if ($report->dryRun) {
            return $report->hasErrors() ? self::FAILURE : self::SUCCESS;
        }
        return $report->success ? self::SUCCESS : self::FAILURE;
    }

    private function renderReport(ImportReport $report): void
    {
        $c = $report->counts;
        $this->line('Curriculum: ' . $c['curricula']);
        $this->line('Versions: ' . $c['versions']);
        $this->line('Phases: ' . $c['phases']);
        $this->line('Grades: ' . $c['grades']);
        $this->line('Fields: ' . $c['fields']);
        $this->line('Contents: ' . $c['contents']);
        $this->line('PDAs: ' . $c['pdas']);
        $this->line('Axes: ' . $c['axes']);
        $this->line('Errors: ' . count($report->errors));

        foreach ($report->warnings as $w) {
            $this->warn(' ! ' . $w);
        }
        foreach ($report->errors as $e) {
            $this->error(sprintf(' x [%s] %s (%s)', $e->code, $e->message, $e->location));
        }

        if ($report->dryRun) {
            $this->line('No changes were written.');
            return;
        }
        if ($report->success) {
            $this->info("Borrador creado: curriculum_version_id={$report->draftId}, número={$report->versionNumber}. No publicado.");
        } else {
            $this->error('Importación cancelada. 0 registros creados.');
        }
    }
}
