<?php

namespace App\Filament\App\Pages;

use App\Actions\Planning\StartPlanningExperiment;
use App\Enums\RoleCode;
use App\Models\Group;
use App\Models\GroupProfile;
use App\Services\Curriculum\ProductionCurriculumPolicy;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class StartPlanning extends Page
{
    protected static ?string $title = 'Nueva planeación';
    protected static ?string $navigationLabel = 'Crear planeación';
    protected static ?string $slug = 'nueva-planeacion';
    protected static ?int $navigationSort = 20;
    protected string $view = 'filament.app.pages.start-planning';

    public ?int $group_id = null;
    public string $starts_on = '';
    public string $ends_on = '';
    public string $work_focus = '';
    public string $context_note = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Customer);
    }

    protected function getViewData(): array
    {
        $eligibleVersionIds = app(ProductionCurriculumPolicy::class)->selectableVersionIds();

        $groups = Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->whereIn('curriculum_version_id', $eligibleVersionIds)
            ->whereHas('profile', function ($query): void {
                foreach (GroupProfile::REQUIRED_FOR_COMPLETENESS as $column) {
                    $query->whereNotNull($column);
                }
            })
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();

        return [
            'groups' => $groups,
            'hasProductionCurriculum' => $eligibleVersionIds !== [],
        ];
    }

    public function selectedGroup(): ?Group
    {
        if (! $this->group_id) {
            return null;
        }

        return Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->with(['grade', 'profile', 'curriculumVersion.curriculum'])
            ->find($this->group_id);
    }

    public function start(): void
    {
        $data = $this->validate([
            'group_id' => ['required', 'integer'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date', 'after_or_equal:starts_on'],
            'work_focus' => ['required', 'string', 'min:3', 'max:255'],
            'context_note' => ['nullable', 'string', 'max:8000'],
        ], [
            'group_id.required' => 'Selecciona el grupo con el que vas a trabajar.',
            'starts_on.required' => 'Indica la fecha de inicio.',
            'ends_on.required' => 'Indica la fecha final.',
            'ends_on.after_or_equal' => 'La fecha final no puede ser anterior a la inicial.',
            'work_focus.required' => 'Cuéntanos qué necesitas trabajar.',
        ]);

        try {
            $request = app(StartPlanningExperiment::class)->execute(
                auth()->user(),
                (int) $data['group_id'],
                $data['starts_on'],
                $data['ends_on'],
                $data['work_focus'],
                $data['context_note'] ?? null,
            );
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'PLANNING_EXPERIMENT_GROUP_NOT_ELIGIBLE') {
                throw ValidationException::withMessages([
                    'group_id' => 'Ese grupo ya no está disponible o le falta completar su perfil pedagógico.',
                ]);
            }
            if ($e->getMessage() === 'PLANNING_EXPERIMENT_CURRICULUM_NOT_PRODUCTION_READY') {
                throw ValidationException::withMessages([
                    'group_id' => 'Este grupo todavía usa un currículo de demostración o no validado. Selecciona el currículo oficial publicado y el grado correcto en Mis grupos.',
                ]);
            }
            throw $e;
        }

        Notification::make()
            ->success()
            ->title('Datos guardados')
            ->body('Ahora revisa las conexiones curriculares antes de generar la planeación.')
            ->send();

        $this->redirect(route('planning.curriculum-map', $request));
    }
}
