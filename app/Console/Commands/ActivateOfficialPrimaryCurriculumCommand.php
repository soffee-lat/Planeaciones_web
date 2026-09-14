<?php

namespace App\Console\Commands;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Enums\RoleCode;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\User;
use App\Services\Curriculum\OfficialPrimaryCurriculumValidator;
use App\Services\Curriculum\ProductionCurriculumPolicy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

final class ActivateOfficialPrimaryCurriculumCommand extends Command
{
    protected $signature = 'curriculum:activate-official-primary
        {version : ID del CurriculumVersion borrador ya importado}
        {--actor= : Email o ID del administrador responsable}';

    protected $description = 'Valida, publica y hace seleccionable el catálogo oficial SEP de Primaria (Fases 3, 4 y 5).';

    public function handle(
        OfficialPrimaryCurriculumValidator $validator,
        PublishCurriculumVersion $publisher,
        ProductionCurriculumPolicy $productionPolicy,
    ): int {
        $actorRef = trim((string) ($this->option('actor') ?? ''));
        if ($actorRef === '') {
            $this->error('Debe indicarse --actor=<email|id> de un administrador activo.');
            return self::FAILURE;
        }

        $actor = ctype_digit($actorRef)
            ? User::find((int) $actorRef)
            : User::where('email', mb_strtolower($actorRef))->first();
        if (! $actor || ! $actor->hasRole(RoleCode::Administrator) || $actor->status !== 'active') {
            $this->error('El actor indicado no es un administrador activo.');
            return self::FAILURE;
        }

        $version = CurriculumVersion::query()
            ->with(['curriculum', 'phases', 'grades.educationalPhase', 'formativeFields', 'curricularContents', 'pdas', 'articulatingAxes'])
            ->find((int) $this->argument('version'));
        if (! $version) {
            $this->error('CurriculumVersion no encontrado.');
            return self::FAILURE;
        }

        try {
            $validator->assertReadyForPublication($version);

            $published = DB::transaction(function () use ($version, $actor, $publisher): CurriculumVersion {
                $published = $publisher($version, $actor);

                $curriculum = Curriculum::query()->whereKey($published->curriculum_id)->lockForUpdate()->firstOrFail();
                $curriculum->forceFill(['selectable_version_id' => $published->id])->save();

                // Los datos DEMO pueden seguir existiendo para tests locales,
                // pero dejan de ser seleccionables en la experiencia docente.
                Curriculum::query()
                    ->where(function ($query): void {
                        $query->whereRaw('LOWER(code) = ?', ['demo'])
                            ->orWhereRaw('LOWER(country_code) = ?', ['demo'])
                            ->orWhereRaw('LOWER(educational_level) = ?', ['demo']);
                    })
                    ->update(['selectable_version_id' => null]);

                return $published->fresh('curriculum');
            }, attempts: 3);

            $productionPolicy->assertPlanningEligible($published);
        } catch (\Throwable $error) {
            $this->error($error->getMessage());
            return self::FAILURE;
        }

        $this->info('Catálogo oficial activado.');
        $this->line('curriculum=' . $published->curriculum?->code);
        $this->line('curriculum_version_id=' . $published->id);
        $this->line('version=' . $published->number . ' · ' . $published->label);
        $this->line('checksum=' . $published->checksum);
        $this->line('contents=' . $published->curricularContents()->count());
        $this->line('pdas=' . $published->pdas()->count());
        $this->line('axes=' . $published->articulatingAxes()->count());
        $this->warn('Los grupos que todavía apuntan al catálogo DEMO no se modificaron automáticamente. Asigna a cada grupo el currículo oficial y su grado real antes de crear nuevas planeaciones.');

        return self::SUCCESS;
    }
}
