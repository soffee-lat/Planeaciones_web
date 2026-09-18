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

    public function mount(int|string $record): void
    {
        $this->record = Group::query()
            ->where('owner_id', auth()->id())
            ->with(['activeSchedule.blocks', 'curriculumVersion.formativeFields'])
            ->findOrFail($record);

        $schedule = $this->record->activeSchedule;
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

        $this->record = $this->record->fresh(['activeSchedule.blocks', 'curriculumVersion.formativeFields']);
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
