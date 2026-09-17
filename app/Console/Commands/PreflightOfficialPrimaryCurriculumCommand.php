<?php

namespace App\Console\Commands;

use App\Models\CurriculumVersion;
use App\Services\Curriculum\OfficialPrimaryCurriculumValidator;
use Illuminate\Console\Command;

final class PreflightOfficialPrimaryCurriculumCommand extends Command
{
    protected $signature = 'curriculum:preflight-official-primary
        {version? : ID del CurriculumVersion borrador ya importado}';

    protected $description = 'Valida sin publicar ni modificar el catálogo oficial SEP de Primaria (Fases 3, 4 y 5).';

    public function handle(OfficialPrimaryCurriculumValidator $validator): int
    {
        $versionRef = trim((string) ($this->argument('version') ?? ''));

        if ($versionRef === '') {
            return $this->listCandidates();
        }

        if (! ctype_digit($versionRef) || (int) $versionRef < 1) {
            $this->error('El argumento version debe ser un ID numérico positivo de CurriculumVersion.');

            return self::FAILURE;
        }

        $version = CurriculumVersion::query()
            ->with([
                'curriculum',
                'phases',
                'grades.educationalPhase',
                'formativeFields',
                'curricularContents',
                'pdas.grade',
                'articulatingAxes',
            ])
            ->find((int) $versionRef);

        if (! $version) {
            $this->error('CurriculumVersion no encontrado.');

            return self::FAILURE;
        }

        try {
            $validator->assertReadyForPublication($version);
        } catch (\Throwable $error) {
            $this->error('Preflight fallido: ' . $error->getMessage());
            $this->warn('No se publicó ni modificó ninguna versión curricular.');

            return self::FAILURE;
        }

        $this->info('Preflight oficial aprobado.');
        $this->line('curriculum=' . $version->curriculum?->code);
        $this->line('curriculum_version_id=' . $version->id);
        $this->line('version=' . $version->number . ' · ' . $version->label);
        $this->line('phases=' . $version->phases->count());
        $this->line('grades=' . $version->grades->count());
        $this->line('fields=' . $version->formativeFields->count());
        $this->line('contents=' . $version->curricularContents->count());
        $this->line('pdas=' . $version->pdas->count());
        $this->line('axes=' . $version->articulatingAxes->count());
        $this->warn('Validación de solo lectura: no se publicó la versión ni se cambió selectable_version_id.');

        return self::SUCCESS;
    }

    private function listCandidates(): int
    {
        $versions = CurriculumVersion::query()
            ->with('curriculum')
            ->whereHas('curriculum', fn ($query) => $query->where('code', OfficialPrimaryCurriculumValidator::CURRICULUM_CODE))
            ->orderByDesc('id')
            ->get();

        if ($versions->isEmpty()) {
            $this->warn('No hay versiones importadas para ' . OfficialPrimaryCurriculumValidator::CURRICULUM_CODE . '.');
            $this->line('Este comando no importa datos; valida una versión ya existente.');

            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Currículo', 'Versión', 'Etiqueta', 'Estado', 'Seleccionable'],
            $versions->map(fn (CurriculumVersion $version): array => [
                $version->id,
                $version->curriculum?->code ?? '—',
                $version->number,
                $version->label,
                $version->isDraft() ? 'borrador' : 'publicada',
                (int) ($version->curriculum?->selectable_version_id ?? 0) === (int) $version->id ? 'sí' : 'no',
            ])->all(),
        );

        $draftIds = $versions
            ->filter(fn (CurriculumVersion $version): bool => $version->isDraft())
            ->pluck('id')
            ->values();

        if ($draftIds->isEmpty()) {
            $this->warn('No hay una versión borrador candidata para ejecutar el preflight.');

            return self::SUCCESS;
        }

        $this->newLine();
        $this->line('Ejecuta el preflight con uno de los ID borrador mostrados arriba. Ejemplo:');
        $this->line('.\\tools\\php.ps1 artisan curriculum:preflight-official-primary ' . $draftIds->first());
        $this->warn('El preflight es de solo lectura y no publica el currículo.');

        return self::SUCCESS;
    }
}
