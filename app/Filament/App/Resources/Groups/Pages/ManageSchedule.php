<?php

namespace App\Filament\App\Resources\Groups\Pages;

use App\Actions\Schedules\SaveGroupSchedule;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\Group;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;

class ManageSchedule extends Page
{
    protected static string $resource = GroupResource::class;

    protected string $view = 'filament.app.resources.groups.pages.manage-schedule';

    public Group $record;

    public string $dayStartsAt = '08:00';

    public string $dayEndsAt = '12:30';

    /** @var array<int,array<string,mixed>> */
    public array $blocks = [];

    /** @var array<int,array{code:string,name:string}> */
    public array $fieldOptions = [];

    public int $defaultBlockMinutes = 50;

    public bool $hasSchedule = false;

    public function mount(Group|int|string $record): void
    {
        if ($record instanceof Group) {
            abort_unless((int) $record->owner_id === (int) auth()->id(), 404);
            $this->record = $record->loadMissing(['activeSchedule.blocks', 'curriculumVersion.formativeFields', 'profile']);
        } else {
            $this->record = Group::query()
                ->where('owner_id', auth()->id())
                ->with(['activeSchedule.blocks', 'curriculumVersion.formativeFields', 'profile'])
                ->findOrFail($record);
        }

        $this->fieldOptions = $this->record->curriculumVersion?->formativeFields
            ?->sortBy('sort_order')
            ->map(fn ($field) => [
                'code' => (string) $field->code,
                'name' => (string) $field->name,
            ])
            ->values()
            ->all() ?? [];
        $this->defaultBlockMinutes = max(15, min(180, (int) ($this->record->profile?->session_minutes ?? 50)));

        $schedule = $this->record->activeSchedule;
        $this->hasSchedule = $schedule !== null;
        if (! $schedule) {
            return;
        }

        $this->dayStartsAt = substr((string) $schedule->day_starts_at, 0, 5);
        $this->dayEndsAt = substr((string) $schedule->day_ends_at, 0, 5);
        $this->blocks = $schedule->blocks->map(fn ($block) => [
            'day_of_week' => (int) $block->day_of_week,
            'sequence' => (int) $block->sequence,
            'starts_at' => substr((string) $block->starts_at, 0, 5),
            'ends_at' => substr((string) $block->ends_at, 0, 5),
            'label' => $block->label,
            'block_type' => $block->block_type,
            'responsibility' => $block->responsibility,
            'include_in_planning' => (bool) $block->include_in_planning,
            'is_flexible' => (bool) $block->is_flexible,
            'field_codes' => array_values($block->field_codes ?? []),
            'notes' => $block->notes,
        ])->values()->all();
    }

    public function saveSchedule(): void
    {
        $schedule = app(SaveGroupSchedule::class)->execute(auth()->user(), $this->record, [
            'day_starts_at' => $this->dayStartsAt,
            'day_ends_at' => $this->dayEndsAt,
            'blocks' => $this->blocks,
        ]);

        $this->record = $this->record->fresh(['activeSchedule.blocks']);
        $this->hasSchedule = true;
        $this->blocks = $schedule->blocks->map(fn ($block) => [
            'day_of_week' => (int) $block->day_of_week,
            'sequence' => (int) $block->sequence,
            'starts_at' => substr((string) $block->starts_at, 0, 5),
            'ends_at' => substr((string) $block->ends_at, 0, 5),
            'label' => $block->label,
            'block_type' => $block->block_type,
            'responsibility' => $block->responsibility,
            'include_in_planning' => (bool) $block->include_in_planning,
            'is_flexible' => (bool) $block->is_flexible,
            'field_codes' => array_values($block->field_codes ?? []),
            'notes' => $block->notes,
        ])->values()->all();

        Notification::make()
            ->success()
            ->title('Horario guardado')
            ->body('Las próximas planeaciones congelarán esta revisión del horario y la usarán para distribuir las actividades.')
            ->send();
    }

    public function getTitle(): string
    {
        return 'Horario · ' . $this->record->name;
    }
}
