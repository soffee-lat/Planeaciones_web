<?php

namespace App\Filament\App\Pages;

use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Schedules\EnsureDefaultGroupSubjects;
use App\Enums\PlanningRequestStatus;
use App\Enums\RoleCode;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\Group;
use App\Models\PlanningRequest;
use App\Services\Planning\PlanningPeriodService;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Illuminate\Validation\ValidationException;

class StartPlanning extends Page
{
    protected static ?string $title = 'Nueva planeación';
    protected static ?string $navigationLabel = 'Nueva planeación';
    protected static ?string $slug = 'nueva-planeacion';
    protected static ?int $navigationSort = 25;
    protected string $view = 'filament.app.pages.start-planning';

    public ?int $draft_id = null;
    public ?int $group_id = null;
    public string $period_type = 'week';
    public string $period_key = '';
    public string $integrative_project = '';
    public string $integrative_project_purpose = '';
    public string $context_note = '';

    /** @var array<int,array<string,mixed>> */
    public array $weeks = [];

    public function mount(): void
    {
        $draftId = request()->integer('draft');
        if ($draftId < 1) {
            return;
        }

        $draft = PlanningRequest::query()
            ->where('owner_id', auth()->id())
            ->where('status', PlanningRequestStatus::BORRADOR->value)
            ->with('planningWeeks.topics')
            ->findOrFail($draftId);

        if (! in_array($draft->period_type, ['week', 'month'], true) || $draft->planningWeeks->isEmpty()) {
            abort(409, 'Esta planeación no usa la estructura semanal editable.');
        }

        $this->draft_id = (int) $draft->id;
        $this->group_id = (int) $draft->group_id;
        $this->period_type = (string) $draft->period_type;
        $this->period_key = (string) $draft->period_key;
        $this->integrative_project = (string) ($draft->integrative_project ?? '');
        $this->integrative_project_purpose = (string) ($draft->integrative_project_purpose ?? '');
        $this->context_note = (string) ($draft->comments ?? '');

        app(EnsureDefaultGroupSubjects::class)->execute($draft->group);

        $this->weeks = $draft->planningWeeks->map(fn ($week) => [
            'sequence' => (int) $week->sequence,
            'starts_on' => $week->starts_on?->toDateString(),
            'ends_on' => $week->ends_on?->toDateString(),
            'label' => (string) $week->label,
            'occupied' => false,
            'topics' => $week->topics->map(fn ($topic) => [
                'topic' => (string) $topic->topic,
                'group_subject_id' => $topic->group_subject_id ? (int) $topic->group_subject_id : null,
                'notes' => (string) ($topic->notes ?? ''),
            ])->values()->all(),
        ])->values()->all();
    }

    public function getTitle(): string
    {
        return $this->draft_id ? 'Editar periodo y temas' : 'Nueva planeación';
    }

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
        return [
            'groups' => PlanningRequestResource::eligibleGroupOptions(),
        ];
    }

    public function updatedGroupId(): void
    {
        $this->period_key = '';
        $this->weeks = [];

        if ($group = $this->selectedGroup()) {
            app(EnsureDefaultGroupSubjects::class)->execute($group);
        }
    }

    public function updatedPeriodType(): void
    {
        $this->period_key = '';
        $this->weeks = [];
        $this->integrative_project = '';
        $this->integrative_project_purpose = '';
    }

    public function updatedPeriodKey(): void
    {
        $this->rebuildWeeks();
    }

    public function addTopic(int $weekIndex): void
    {
        if (! isset($this->weeks[$weekIndex])) {
            return;
        }

        $this->weeks[$weekIndex]['topics'][] = [
            'topic' => '',
            'group_subject_id' => null,
            'notes' => '',
        ];
    }

    public function removeTopic(int $weekIndex, int $topicIndex): void
    {
        if (! isset($this->weeks[$weekIndex]['topics'][$topicIndex])) {
            return;
        }

        unset($this->weeks[$weekIndex]['topics'][$topicIndex]);
        $this->weeks[$weekIndex]['topics'] = array_values($this->weeks[$weekIndex]['topics']);

        if ($this->weeks[$weekIndex]['topics'] === []) {
            $this->addTopic($weekIndex);
        }
    }

    /** @return array<string,string> */
    public function periodOptions(): array
    {
        $group = $this->selectedGroup();
        if (! $group) {
            return [];
        }

        $periods = app(PlanningPeriodService::class);
        $options = $this->period_type === 'month'
            ? $periods->cycleMonths((string) $group->school_year)
            : $periods->cycleWeeks((string) $group->school_year);

        if ($options === []) {
            return [];
        }

        $from = $options[0]['starts_on'];
        $to = $options[count($options) - 1]['ends_on'];
        $existing = $this->existingRanges($group->id, $from, $to);

        $result = [];
        foreach ($options as $option) {
            $occupied = $existing->contains(fn (PlanningRequest $request) =>
                $request->starts_on?->toDateString() <= $option['ends_on']
                && $request->ends_on?->toDateString() >= $option['starts_on']
            );

            $result[$option['key']] = $option['label']
                . ($occupied
                    ? ($this->period_type === 'week' ? '  ·  ⚠ Ya tiene planeación' : '  ·  ⚠ Contiene periodos planeados')
                    : '');
        }

        return $result;
    }

    /** @return array<int,string> */
    public function subjectOptions(): array
    {
        $group = $this->selectedGroup();
        if (! $group) {
            return [];
        }

        return $group->subjects()
            ->where('is_active', true)
            ->orderByRaw("CASE WHEN origin = 'official' THEN 0 ELSE 1 END")
            ->orderBy('name')
            ->pluck('name', 'id')
            ->all();
    }

    public function selectedGroup(): ?Group
    {
        if (! $this->group_id) {
            return null;
        }

        return Group::query()
            ->where('owner_id', auth()->id())
            ->whereNull('archived_at')
            ->with(['grade', 'profile'])
            ->find($this->group_id);
    }

    public function start(): void
    {
        $data = $this->validate([
            'group_id' => ['required', 'integer'],
            'period_type' => ['required', 'string', 'in:week,month'],
            'period_key' => ['required', 'string', 'max:32'],
            'integrative_project' => ['nullable', 'string', 'max:255'],
            'integrative_project_purpose' => ['nullable', 'string', 'max:8000'],
            'context_note' => ['nullable', 'string', 'max:8000'],
            'weeks' => ['required', 'array', 'min:1'],
            'weeks.*.sequence' => ['required', 'integer', 'min:1'],
            'weeks.*.topics' => ['required', 'array', 'min:1'],
            'weeks.*.topics.*.topic' => ['required', 'string', 'min:2', 'max:255'],
            'weeks.*.topics.*.group_subject_id' => ['required', 'integer', 'min:1'],
            'weeks.*.topics.*.notes' => ['nullable', 'string', 'max:4000'],
        ], [
            'group_id.required' => 'Selecciona el grupo con el que vas a trabajar.',
            'period_key.required' => 'Selecciona la semana o el mes que vas a planear.',
            'weeks.*.topics.*.topic.required' => 'Escribe el tema que se trabajará.',
            'weeks.*.topics.*.group_subject_id.required' => 'Selecciona la materia principal del tema.',
        ]);

        $group = $this->selectedGroup();
        if (! $group) {
            throw ValidationException::withMessages([
                'group_id' => 'Ese grupo ya no está disponible o está archivado.',
            ]);
        }

        try {
            $period = app(PlanningPeriodService::class)->resolve($data['period_type'], $data['period_key']);
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages([
                'period_key' => 'El periodo seleccionado ya no es válido.',
            ]);
        }

        $topicNames = collect($data['weeks'])
            ->flatMap(fn (array $week) => collect($week['topics'] ?? [])->pluck('topic'))
            ->map(fn ($topic) => trim((string) $topic))
            ->filter()
            ->unique()
            ->values();

        $workFocus = trim((string) ($data['integrative_project'] ?? ''));
        if ($workFocus === '') {
            $workFocus = (string) ($topicNames->first() ?? 'Planeación');
        }

        $structure = [
            'period_type' => $data['period_type'],
            'period_key' => $data['period_key'],
            'integrative_project' => $data['integrative_project'] ?? null,
            'integrative_project_purpose' => $data['integrative_project_purpose'] ?? null,
            'context_note' => $data['context_note'] ?? null,
            'weeks' => $data['weeks'],
        ];

        try {
            if ($this->draft_id) {
                $request = PlanningRequest::query()
                    ->where('owner_id', auth()->id())
                    ->where('status', PlanningRequestStatus::BORRADOR->value)
                    ->findOrFail($this->draft_id);

                if ((int) $request->group_id !== (int) $data['group_id']) {
                    throw ValidationException::withMessages([
                        'group_id' => 'No puedes cambiar el grupo de una planeación ya iniciada.',
                    ]);
                }

                $request = app(\App\Actions\Planning\SyncPlanningPedagogicalStructure::class)->execute(
                    auth()->user(),
                    $request,
                    $structure,
                );
            } else {
                $request = app(StartPlanningExperiment::class)->execute(
                    auth()->user(),
                    (int) $data['group_id'],
                    $period['starts_on'],
                    $period['ends_on'],
                    $workFocus,
                    $data['context_note'] ?? null,
                    $structure,
                );
            }
        } catch (\RuntimeException $e) {
            if ($e->getMessage() === 'PLANNING_EXPERIMENT_GROUP_NOT_ELIGIBLE') {
                throw ValidationException::withMessages([
                    'group_id' => 'Ese grupo ya no está disponible o le falta completar su perfil pedagógico.',
                ]);
            }
            throw $e;
        }

        Notification::make()
            ->success()
            ->title('Estructura de la planeación lista')
            ->body('Ahora revisa las conexiones curriculares que corresponden a los temas que definiste.')
            ->send();

        $this->redirect(route('planning.curriculum-map', $request));
    }

    private function rebuildWeeks(): void
    {
        if ($this->period_key === '' || ! $this->selectedGroup()) {
            $this->weeks = [];
            return;
        }

        try {
            $period = app(PlanningPeriodService::class)->resolve($this->period_type, $this->period_key);
        } catch (\InvalidArgumentException) {
            $this->weeks = [];
            return;
        }

        $existing = $this->existingRanges(
            (int) $this->group_id,
            $period['starts_on'],
            $period['ends_on'],
        );

        $this->weeks = array_map(function (array $week) use ($existing): array {
            $occupied = $existing->contains(fn (PlanningRequest $request) =>
                $request->starts_on?->toDateString() <= $week['ends_on']
                && $request->ends_on?->toDateString() >= $week['starts_on']
            );

            return [
                ...$week,
                'occupied' => $occupied,
                'topics' => [[
                    'topic' => '',
                    'group_subject_id' => null,
                    'notes' => '',
                ]],
            ];
        }, $period['weeks']);
    }

    private function existingRanges(int $groupId, string $from, string $to): \Illuminate\Support\Collection
    {
        return PlanningRequest::query()
            ->where('owner_id', auth()->id())
            ->where('group_id', $groupId)
            ->where('status', '!=', PlanningRequestStatus::CANCELADA->value)
            ->whereDate('starts_on', '<=', $to)
            ->whereDate('ends_on', '>=', $from)
            ->when($this->draft_id, fn ($query) => $query->whereKeyNot($this->draft_id))
            ->get(['id', 'starts_on', 'ends_on', 'status', 'period_type']);
    }
}
