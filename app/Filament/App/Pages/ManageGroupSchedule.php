<?php

namespace App\Filament\App\Pages;

use App\Actions\Pedagogy\UpdateGroupSchedule;
use App\Enums\RoleCode;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\FormativeField;
use App\Models\Group;
use App\Services\Planning\PlanningCalendarBuilder;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ManageGroupSchedule extends Page
{
    protected static ?string $title = 'Horario';
    protected static ?string $slug = 'groups/{group}/schedule';
    protected static bool $shouldRegisterNavigation = false;
    protected string $view = 'filament.app.pages.manage-group-schedule';

    public int $groupId;
    public string $groupName = '';
    public array $initialSchedule = [];
    public array $fieldOptions = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Customer);
    }

    public function mount(int|string $group): void
    {
        /** @var Group $record */
        $record = Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->with('schedule.blocks')
            ->findOrFail($group);

        Gate::authorize('update', $record);

        $this->groupId = (int) $record->id;
        $this->groupName = (string) $record->name;
        $this->fieldOptions = FormativeField::query()
            ->where('curriculum_version_id', $record->curriculum_version_id)
            ->orderBy('sort_order')
            ->get(['code', 'name'])
            ->map(fn (FormativeField $field) => [
                'code' => (string) $field->code,
                'name' => (string) $field->name,
            ])
            ->values()
            ->all();

        $this->reloadSchedule();
    }

    public function getTitle(): string
    {
        return 'Horario · ' . $this->groupName;
    }

    public function backUrl(): string
    {
        return GroupResource::getUrl('edit', ['record' => $this->groupId]);
    }

    /** @param array<string,mixed> $payload @return array<string,mixed> */
    public function saveSchedule(array $payload): array
    {
        /** @var Group $group */
        $group = Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->findOrFail($this->groupId);

        try {
            $schedule = app(UpdateGroupSchedule::class)->execute(auth()->user(), $group, $payload);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: 'Revisa los bloques del horario.';

            Notification::make()
                ->danger()
                ->title('No se pudo guardar el horario')
                ->body((string) $message)
                ->send();

            throw $e;
        }

        $snapshot = app(PlanningCalendarBuilder::class)->scheduleSnapshot($schedule);
        $this->initialSchedule = $snapshot;

        Notification::make()
            ->success()
            ->title('Horario guardado')
            ->body('Las siguientes planeaciones de este grupo usarán esta revisión del horario.')
            ->send();

        return $snapshot;
    }

    private function reloadSchedule(): void
    {
        $group = Group::query()
            ->whereKey($this->groupId)
            ->with('schedule.blocks')
            ->firstOrFail();

        if ($group->schedule) {
            $this->initialSchedule = app(PlanningCalendarBuilder::class)->scheduleSnapshot($group->schedule);
            return;
        }

        $this->initialSchedule = [
            'schema_version' => 1,
            'revision' => 0,
            'name' => 'Horario habitual',
            'active_days' => [1, 2, 3, 4, 5],
            'exceptions' => [],
            'day_starts_at' => '08:00',
            'day_ends_at' => '12:30',
            'blocks' => [],
        ];
    }
}
