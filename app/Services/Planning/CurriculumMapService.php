<?php

namespace App\Services\Planning;

use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Enums\ProductEventType;
use App\Enums\RoleCode;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Models\ProductEvent;
use App\Models\User;
use App\Services\Analytics\ProductEventRecorder;
use Illuminate\Support\Facades\DB;

final class CurriculumMapService
{
    public function __construct(
        private CurriculumSuggestionService $suggestions,
        private ProductEventRecorder $events,
        private SyncPlanningRequestSelections $syncSelections,
    ) {}

    /** @return array<string,mixed> */
    public function state(User $actor, PlanningRequest $request, bool $recordShown = true): array
    {
        $this->assertEditable($actor, $request);
        $request->refresh();

        $suggestion = $this->suggest($request);
        $suggestionFingerprint = $this->suggestionFingerprint($request, $suggestion);

        if ($recordShown && ! ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::CurriculumSuggestionsShown->value)
            ->where('metadata->suggestion_fingerprint', $suggestionFingerprint)
            ->exists()) {
            $this->events->record($actor, ProductEventType::CurriculumSuggestionsShown, request: $request, metadata: [
                'strategy_version' => $suggestion['strategy_version'],
                'suggestion_fingerprint' => $suggestionFingerprint,
                'content_count' => count($suggestion['content_ids']),
                'pda_count' => count($suggestion['pda_ids']),
                'axis_count' => count($suggestion['axis_ids']),
                'formative_field_count' => count($suggestion['formative_field_ids']),
                'has_strong_match' => (bool) $suggestion['has_strong_match'],
            ]);
        }

        $statuses = [
            'content' => $this->initialStatuses($suggestion['content_ids']),
            'pda' => $this->initialStatuses($suggestion['pda_ids']),
            'axis' => $this->initialStatuses($suggestion['axis_ids']),
        ];
        $origins = [
            'content' => array_fill_keys(array_map('intval', $suggestion['content_ids']), 'suggested'),
            'pda' => array_fill_keys(array_map('intval', $suggestion['pda_ids']), 'suggested'),
            'axis' => array_fill_keys(array_map('intval', $suggestion['axis_ids']), 'suggested'),
        ];

        $decisionEvents = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->whereIn('event_type', [
                ProductEventType::CurriculumSuggestionAccepted->value,
                ProductEventType::CurriculumSuggestionRejected->value,
                ProductEventType::CurriculumSelectionAdded->value,
            ])
            ->orderBy('id')
            ->get();

        foreach ($decisionEvents as $event) {
            $metadata = $event->metadata ?? [];
            if (($metadata['suggestion_fingerprint'] ?? null) !== $suggestionFingerprint) {
                continue;
            }
            $entityType = $metadata['entity_type'] ?? null;
            $entityId = isset($metadata['entity_id']) ? (int) $metadata['entity_id'] : null;
            if (! in_array($entityType, ['content', 'pda', 'axis'], true) || ! $entityId) {
                continue;
            }

            $origins[$entityType][$entityId] = (string) ($metadata['origin'] ?? 'suggested');
            $statuses[$entityType][$entityId] = match ($event->event_type) {
                ProductEventType::CurriculumSuggestionAccepted,
                ProductEventType::CurriculumSelectionAdded => 'accepted',
                ProductEventType::CurriculumSuggestionRejected => 'rejected',
                default => $statuses[$entityType][$entityId] ?? 'pending',
            };
        }

        $contents = CurricularContent::query()
            ->with('formativeField')
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereIn('id', array_keys($statuses['content']) ?: [0])
            ->orderBy('sort_order')->orderBy('code')
            ->get()->keyBy('id');
        $pdas = Pda::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->where('grade_id', $request->grade_id)
            ->whereIn('id', array_keys($statuses['pda']) ?: [0])
            ->orderBy('sort_order')->orderBy('code')
            ->get()->keyBy('id');
        $axes = ArticulatingAxis::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereIn('id', array_keys($statuses['axis']) ?: [0])
            ->orderBy('sort_order')->orderBy('code')
            ->get()->keyBy('id');

        $selected = [
            'contents' => $this->acceptedIds($statuses['content']),
            'pdas' => $this->acceptedIds($statuses['pda']),
            'axes' => $this->acceptedIds($statuses['axis']),
        ];

        return [
            'request' => $request,
            'suggestion' => $suggestion,
            'suggestion_fingerprint' => $suggestionFingerprint,
            'statuses' => $statuses,
            'origins' => $origins,
            'contents' => $contents,
            'pdas' => $pdas,
            'axes' => $axes,
            'selected' => $selected,
            'pending_count' => $this->pendingCount($statuses),
            'catalog' => $this->catalogOptions($request),
        ];
    }

    public function decide(User $actor, PlanningRequest $request, string $entityType, int $entityId, bool $accept): void
    {
        $state = $this->state($actor, $request, false);
        $this->assertEntityType($entityType);

        if (! array_key_exists($entityId, $state['statuses'][$entityType])) {
            throw new \RuntimeException('CURRICULUM_MAP_ENTITY_NOT_AVAILABLE');
        }

        $this->events->record(
            $actor,
            $accept ? ProductEventType::CurriculumSuggestionAccepted : ProductEventType::CurriculumSuggestionRejected,
            request: $request,
            metadata: [
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'origin' => $state['origins'][$entityType][$entityId] ?? 'suggested',
                'suggestion_fingerprint' => $state['suggestion_fingerprint'],
            ],
        );
    }

    public function acceptAllSuggested(User $actor, PlanningRequest $request): void
    {
        $state = $this->state($actor, $request, false);
        foreach (['content', 'pda', 'axis'] as $type) {
            foreach ($state['statuses'][$type] as $id => $status) {
                if ($status !== 'pending' || ($state['origins'][$type][$id] ?? null) !== 'suggested') {
                    continue;
                }
                $this->events->record($actor, ProductEventType::CurriculumSuggestionAccepted, request: $request, metadata: [
                    'entity_type' => $type,
                    'entity_id' => (int) $id,
                    'origin' => 'suggested',
                    'suggestion_fingerprint' => $state['suggestion_fingerprint'],
                ]);
            }
        }
    }

    public function add(User $actor, PlanningRequest $request, string $entityType, int $entityId): void
    {
        $this->assertEditable($actor, $request);
        $this->assertEntityType($entityType);
        $this->assertCompatibleEntity($request, $entityType, $entityId);

        $state = $this->state($actor, $request, false);

        // Un PDA nunca debe quedar huérfano en el mapa. Si el docente agrega
        // uno cuyo contenido no estaba incluido, agregamos también el contenido.
        if ($entityType === 'pda') {
            $pda = Pda::query()->findOrFail($entityId);
            $contentId = (int) $pda->curricular_content_id;
            if (($state['statuses']['content'][$contentId] ?? null) !== 'accepted') {
                $this->events->record($actor, ProductEventType::CurriculumSelectionAdded, request: $request, metadata: [
                    'entity_type' => 'content',
                    'entity_id' => $contentId,
                    'origin' => 'teacher_added',
                    'suggestion_fingerprint' => $state['suggestion_fingerprint'],
                ]);
            }
        }

        $this->events->record($actor, ProductEventType::CurriculumSelectionAdded, request: $request, metadata: [
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'origin' => 'teacher_added',
            'suggestion_fingerprint' => $state['suggestion_fingerprint'],
        ]);
    }

    public function confirm(User $actor, PlanningRequest $request): PlanningRequest
    {
        $state = $this->state($actor, $request, false);
        if ($state['pending_count'] > 0) {
            throw new \RuntimeException('CURRICULUM_MAP_HAS_PENDING_DECISIONS');
        }

        $selected = $state['selected'];
        if ($selected['contents'] === []) {
            throw new \RuntimeException('CURRICULUM_MAP_CONTENT_REQUIRED');
        }
        if ($selected['pdas'] === []) {
            throw new \RuntimeException('CURRICULUM_MAP_PDA_REQUIRED');
        }

        $pdaContentIds = Pda::query()
            ->whereIn('id', $selected['pdas'])
            ->pluck('curricular_content_id')
            ->map(fn ($id) => (int) $id)
            ->unique()
            ->values()
            ->all();
        $selectedContentIds = $this->sortedInts($selected['contents']);

        foreach ($selectedContentIds as $contentId) {
            if (! in_array($contentId, $pdaContentIds, true)) {
                throw new \RuntimeException('CURRICULUM_MAP_CONTENT_WITHOUT_PDA:' . $contentId);
            }
        }
        foreach ($pdaContentIds as $contentId) {
            if (! in_array($contentId, $selectedContentIds, true)) {
                throw new \RuntimeException('CURRICULUM_MAP_PDA_WITHOUT_CONTENT:' . $contentId);
            }
        }

        $synced = $this->syncSelections->execute($actor, $request, [
            'contents' => $selectedContentIds,
            'pdas' => $selected['pdas'],
            'axes' => $selected['axes'],
        ]);

        $selectionFingerprint = $this->selectionFingerprint($synced, [
            'contents' => $selectedContentIds,
            'pdas' => $selected['pdas'],
            'axes' => $selected['axes'],
        ]);

        $confirmed = DB::transaction(function () use ($synced, $selectionFingerprint): PlanningRequest {
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($synced->id);
            if (! $fresh->isDraft()) {
                throw new \RuntimeException('PLANNING_REQUEST_ALREADY_CONFIRMED');
            }
            $fresh->forceFill([
                'curriculum_confirmed_at' => now(),
                'curriculum_selection_fingerprint' => $selectionFingerprint,
            ])->save();

            return $fresh->refresh();
        }, attempts: 3);

        $this->events->record($actor, ProductEventType::CurriculumMapConfirmed, request: $confirmed, metadata: [
            'selection_revision' => (int) $confirmed->selection_revision,
            'fingerprint' => $selectionFingerprint,
            'content_count' => count($selectedContentIds),
            'pda_count' => count($selected['pdas']),
            'axis_count' => count($selected['axes']),
        ]);

        return $confirmed;
    }

    /** @return array<string,array<int,string>> */
    private function catalogOptions(PlanningRequest $request): array
    {
        $contents = CurricularContent::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereHas('pdas', fn ($query) => $query->where('grade_id', $request->grade_id))
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'title'])
            ->mapWithKeys(fn ($content) => [$content->id => $content->code . ' — ' . $content->title])
            ->all();

        $pdas = Pda::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->where('grade_id', $request->grade_id)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'full_text'])
            ->mapWithKeys(fn ($pda) => [$pda->id => $pda->code . ' — ' . mb_strimwidth((string) $pda->full_text, 0, 110, '…')])
            ->all();

        $axes = ArticulatingAxis::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($axis) => [$axis->id => $axis->code . ' — ' . $axis->name])
            ->all();

        return compact('contents', 'pdas', 'axes');
    }

    /** @return array<string,mixed> */
    private function suggest(PlanningRequest $request): array
    {
        return $this->suggestions->suggest(
            (int) $request->curriculum_version_id,
            (int) $request->grade_id,
            [
                'project' => $request->project,
                'topic' => $request->topic,
                'book_pages' => $request->book_pages,
                'required_activities' => $request->required_activities,
                'special_events' => $request->special_events,
                'comments' => $request->comments,
            ],
        );
    }

    /** @param array<string,mixed> $suggestion */
    private function suggestionFingerprint(PlanningRequest $request, array $suggestion): string
    {
        // La huella depende del contexto que alimenta la sugerencia y del
        // resultado sugerido; NO de input_revision, porque sincronizar pivotes
        // incrementa esa revisión y no debe borrar las decisiones recién tomadas.
        $payload = [
            'strategy' => $suggestion['strategy_version'],
            'curriculum_version_id' => (int) $request->curriculum_version_id,
            'grade_id' => (int) $request->grade_id,
            'input' => [
                'project' => (string) ($request->project ?? ''),
                'topic' => (string) ($request->topic ?? ''),
                'book_pages' => (string) ($request->book_pages ?? ''),
                'required_activities' => (string) ($request->required_activities ?? ''),
                'special_events' => (string) ($request->special_events ?? ''),
                'comments' => (string) ($request->comments ?? ''),
            ],
            'contents' => $this->sortedInts($suggestion['content_ids']),
            'pdas' => $this->sortedInts($suggestion['pda_ids']),
            'axes' => $this->sortedInts($suggestion['axis_ids']),
            'fields' => $this->sortedInts($suggestion['formative_field_ids']),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param array{contents:int[],pdas:int[],axes:int[]} $selected */
    private function selectionFingerprint(PlanningRequest $request, array $selected): string
    {
        $payload = [
            'curriculum_version_id' => (int) $request->curriculum_version_id,
            'grade_id' => (int) $request->grade_id,
            'contents' => $this->sortedInts($selected['contents']),
            'pdas' => $this->sortedInts($selected['pdas']),
            'axes' => $this->sortedInts($selected['axes']),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /** @param int[] $ids @return array<int,string> */
    private function initialStatuses(array $ids): array
    {
        $result = [];
        foreach ($ids as $id) {
            $result[(int) $id] = 'pending';
        }
        return $result;
    }

    /** @param array<int,string> $statuses @return int[] */
    private function acceptedIds(array $statuses): array
    {
        $ids = [];
        foreach ($statuses as $id => $status) {
            if ($status === 'accepted') {
                $ids[] = (int) $id;
            }
        }
        sort($ids);
        return $ids;
    }

    /** @param array<string,array<int,string>> $statuses */
    private function pendingCount(array $statuses): int
    {
        $count = 0;
        foreach ($statuses as $items) {
            foreach ($items as $status) {
                if ($status === 'pending') {
                    $count++;
                }
            }
        }
        return $count;
    }

    /** @param int[] $values @return int[] */
    private function sortedInts(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values);
        return $values;
    }

    private function assertEntityType(string $entityType): void
    {
        if (! in_array($entityType, ['content', 'pda', 'axis'], true)) {
            throw new \RuntimeException('CURRICULUM_MAP_ENTITY_TYPE_INVALID');
        }
    }

    private function assertCompatibleEntity(PlanningRequest $request, string $entityType, int $entityId): void
    {
        $exists = match ($entityType) {
            'content' => CurricularContent::query()
                ->whereKey($entityId)
                ->where('curriculum_version_id', $request->curriculum_version_id)
                ->whereHas('pdas', fn ($query) => $query->where('grade_id', $request->grade_id))
                ->exists(),
            'pda' => Pda::query()
                ->whereKey($entityId)
                ->where('curriculum_version_id', $request->curriculum_version_id)
                ->where('grade_id', $request->grade_id)
                ->exists(),
            'axis' => ArticulatingAxis::query()
                ->whereKey($entityId)
                ->where('curriculum_version_id', $request->curriculum_version_id)
                ->exists(),
            default => false,
        };

        if (! $exists) {
            throw new \RuntimeException('CURRICULUM_MAP_ENTITY_NOT_COMPATIBLE');
        }
    }

    private function assertEditable(User $actor, PlanningRequest $request): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Customer)) {
            throw new \RuntimeException('CURRICULUM_MAP_ACTOR_REQUIRED');
        }
        if ((int) $request->owner_id !== (int) $actor->id) {
            throw new \RuntimeException('CURRICULUM_MAP_OWNER_MISMATCH');
        }
        if (! $request->isDraft()) {
            throw new \RuntimeException('CURRICULUM_MAP_DRAFT_REQUIRED');
        }
    }
}
