<?php

namespace App\Filament\App\Resources\Groups\Pages;

use App\Actions\Schedules\CreateGroupSubject;
use App\Actions\Schedules\EnsureDefaultGroupSubjects;
use App\Actions\Schedules\SaveGroupSchedule;
use App\Actions\Schedules\UpdateGroupSubjectColor;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\Group;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\Page;
use Illuminate\Support\Collection;

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

    /** @var array<int,array<string,mixed>> */
    public array $subjectCatalog = [];

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

        app(EnsureDefaultGroupSubjects::class)->execute($this->record);
        $this->refreshSubjectCatalog();

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
        $this->blocks = $this->serializeBlocks($schedule->blocks);
    }

    /** @param array{name:mixed,color:mixed} $data */
    public function createSubject(array $data): array
    {
        $subject = app(CreateGroupSubject::class)->execute(
            auth()->user(),
            $this->record,
            $data,
        );

        $this->refreshSubjectCatalog();

        return $this->serializeSubject($subject);
    }

    public function updateSubjectColor(int $subjectId, string $color): array
    {
        $subject = $this->record->subjects()->findOrFail($subjectId);

        $subject = app(UpdateGroupSubjectColor::class)->execute(
            auth()->user(),
            $this->record,
            $subject,
            $color,
        );

        $this->refreshSubjectCatalog();

        return $this->serializeSubject($subject);
    }

    public function saveSchedule(): void
    {
        $schedule = app(SaveGroupSchedule::class)->execute(auth()->user(), $this->record, [
            'day_starts_at' => $this->dayStartsAt,
            'day_ends_at' => $this->dayEndsAt,
            'blocks' => $this->blocks,
        ]);

        $this->record = $this->record->fresh(['activeSchedule.blocks', 'subjects']);
        $this->hasSchedule = true;
        $this->refreshSubjectCatalog();
        $this->blocks = $this->serializeBlocks($schedule->blocks);

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

    private function refreshSubjectCatalog(): void
    {
        $this->record->unsetRelation('subjects');

        $this->subjectCatalog = $this->record->subjects()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN origin = 'official' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->get()
            ->map(fn ($subject) => $this->serializeSubject($subject))
            ->values()
            ->all();
    }

    /** @return array<string,mixed> */
    private function serializeSubject($subject): array
    {
        return [
            'id' => (int) $subject->id,
            'name' => (string) $subject->name,
            'color' => strtoupper((string) $subject->color),
            'origin' => (string) $subject->origin,
            'curriculum_field_code' => $subject->curriculum_field_code
                ? (string) $subject->curriculum_field_code
                : null,
        ];
    }

    /** @return array<int,array<string,mixed>> */
    private function serializeBlocks(Collection $blocks): array
    {
        $subjectsById = collect($this->subjectCatalog)->keyBy('id');
        $subjectsByName = collect($this->subjectCatalog)->keyBy(
            fn (array $subject) => mb_strtolower(trim($subject['name'])),
        );

        return $blocks->map(function ($block) use ($subjectsById, $subjectsByName) {
            $subject = $block->group_subject_id
                ? $subjectsById->get((int) $block->group_subject_id)
                : null;

            if (! $subject && ! in_array($block->block_type, ['break', 'unavailable'], true)) {
                $subject = $subjectsByName->get(mb_strtolower(trim((string) $block->label)));
            }

            return [
                'day_of_week' => (int) $block->day_of_week,
                'sequence' => (int) $block->sequence,
                'starts_at' => substr((string) $block->starts_at, 0, 5),
                'ends_at' => substr((string) $block->ends_at, 0, 5),
                'label' => $subject['name'] ?? $block->label,
                'group_subject_id' => $subject['id'] ?? ($block->group_subject_id ? (int) $block->group_subject_id : null),
                'subject_name_snapshot' => $subject['name'] ?? $block->subject_name_snapshot,
                'subject_color_snapshot' => $subject['color'] ?? $block->subject_color_snapshot,
                'block_type' => $block->block_type,
                'responsibility' => $block->responsibility,
                'include_in_planning' => (bool) $block->include_in_planning,
                'is_flexible' => (bool) $block->is_flexible,
                'field_codes' => array_values($block->field_codes ?? []),
                'notes' => $block->notes,
            ];
        })->values()->all();
    }
}
