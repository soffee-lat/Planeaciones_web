<?php

namespace App\Services\Planning;

use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Enums\ProductEventType;
use App\Enums\RoleCode;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\FormativeField;
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
        $request->loadMissing('planningWeeks.topics.subject');

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

        $scheduleFieldCoverage = $this->scheduleFieldCoverage($request, $selected['contents'], $selected['pdas']);
        $scheduleFieldOptions = $this->scheduleFieldOptions(
            $request,
            $scheduleFieldCoverage,
            $suggestion,
            $selected['contents'],
            $selected['pdas'],
            $statuses,
        );

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
            'schedule_field_coverage' => $scheduleFieldCoverage,
            'schedule_field_options' => $scheduleFieldOptions,
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

        $scheduleFieldCoverage = $this->scheduleFieldCoverage($request, $selectedContentIds, $selected['pdas']);
        if ($scheduleFieldCoverage['missing'] !== []) {
            throw new \RuntimeException(
                'CURRICULUM_MAP_SCHEDULE_FIELD_REQUIRED:'
                . implode(',', array_column($scheduleFieldCoverage['missing'], 'code'))
            );
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
            if (! $fresh->canEditInputs()) {
                throw new \RuntimeException('PLANNING_REQUEST_INPUTS_NOT_EDITABLE');
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

    /** @return array<string,mixed> */
    private function catalogOptions(PlanningRequest $request): array
    {
        $contentModels = CurricularContent::query()
            ->with('formativeField:id,code,name')
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereHas('pdas', fn ($query) => $query->where('grade_id', $request->grade_id))
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'formative_field_id', 'code', 'title']);

        $contents = $contentModels
            ->mapWithKeys(fn ($content) => [
                $content->id => $content->code
                    . ' — ' . ($content->formativeField?->name ?? 'Sin campo')
                    . ' — ' . $content->title,
            ])
            ->all();

        $contentFieldCodes = $contentModels
            ->mapWithKeys(fn ($content) => [
                $content->id => (string) ($content->formativeField?->code ?? ''),
            ])
            ->all();

        $pdaModels = Pda::query()
            ->with('curricularContent.formativeField:id,code,name')
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->where('grade_id', $request->grade_id)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'curricular_content_id', 'code', 'full_text']);

        $pdas = $pdaModels
            ->mapWithKeys(function ($pda) {
                $fieldName = $pda->curricularContent?->formativeField?->name ?? 'Sin campo';

                return [
                    $pda->id => $pda->code
                        . ' — ' . $fieldName
                        . ' — ' . mb_strimwidth((string) $pda->full_text, 0, 110, '…'),
                ];
            })
            ->all();

        $pdaFieldCodes = $pdaModels
            ->mapWithKeys(fn ($pda) => [
                $pda->id => (string) ($pda->curricularContent?->formativeField?->code ?? ''),
            ])
            ->all();

        $axes = ArticulatingAxis::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->orderBy('sort_order')->orderBy('code')
            ->get(['id', 'code', 'name'])
            ->mapWithKeys(fn ($axis) => [$axis->id => $axis->code . ' — ' . $axis->name])
            ->all();

        return [
            'contents' => $contents,
            'pdas' => $pdas,
            'axes' => $axes,
            'content_field_codes' => $contentFieldCodes,
            'pda_field_codes' => $pdaFieldCodes,
        ];
    }

    /**
     * Devuelve los códigos de campo oficial exigidos por bloques no flexibles
     * que sí participan en la planeación.
     *
     * @return list<string>
     */
    private function requiredScheduleFieldCodes(PlanningRequest $request): array
    {
        $group = $request->group()->with('activeSchedule.blocks')->first();
        $schedule = $group?->activeSchedule;

        if (! $schedule) {
            return [];
        }

        $requiredCodes = [];
        foreach ($schedule->blocks as $block) {
            if (! $block->include_in_planning || $block->is_flexible) {
                continue;
            }

            foreach ((array) ($block->field_codes ?? []) as $code) {
                $code = trim((string) $code);
                if ($code !== '') {
                    $requiredCodes[$code] = true;
                }
            }
        }

        $requiredCodes = array_keys($requiredCodes);
        sort($requiredCodes, SORT_STRING);

        return array_values($requiredCodes);
    }

    /**
     * Garantiza que cada campo oficial no flexible del horario que entra en la
     * planeación tenga tanto contenido curricular como al menos un PDA elegido.
     *
     * @param int[] $selectedContentIds
     * @param int[] $selectedPdaIds
     * @return array{
     *   required:list<array{
     *     code:string,
     *     name:string,
     *     content_selected:bool,
     *     pda_selected:bool,
     *     covered:bool,
     *     missing_requirement:?string
     *   }>,
     *   missing:list<array{
     *     code:string,
     *     name:string,
     *     content_selected:bool,
     *     pda_selected:bool,
     *     missing_requirement:string
     *   }>
     * }
     */
    private function scheduleFieldCoverage(
        PlanningRequest $request,
        array $selectedContentIds,
        array $selectedPdaIds,
    ): array
    {
        $requiredCodes = $this->requiredScheduleFieldCodes($request);

        if ($requiredCodes === []) {
            return ['required' => [], 'missing' => []];
        }

        $selectedContentFieldCodes = CurricularContent::query()
            ->with('formativeField:id,code,name')
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereIn('id', $selectedContentIds ?: [0])
            ->get(['id', 'formative_field_id'])
            ->map(fn ($content) => (string) ($content->formativeField?->code ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $selectedPdaFieldCodes = Pda::query()
            ->with('curricularContent.formativeField:id,code,name')
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->where('grade_id', $request->grade_id)
            ->whereIn('id', $selectedPdaIds ?: [0])
            ->get(['id', 'curricular_content_id'])
            ->map(fn ($pda) => (string) ($pda->curricularContent?->formativeField?->code ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $fieldNames = FormativeField::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereIn('code', $requiredCodes)
            ->pluck('name', 'code')
            ->all();

        $required = array_map(function (string $code) use ($fieldNames, $selectedContentFieldCodes, $selectedPdaFieldCodes): array {
            $hasContent = in_array($code, $selectedContentFieldCodes, true);
            $hasPda = in_array($code, $selectedPdaFieldCodes, true);
            $missingRequirement = match (true) {
                $hasContent && $hasPda => null,
                ! $hasContent && ! $hasPda => 'content_and_pda',
                ! $hasContent => 'content',
                default => 'pda',
            };

            return [
                'code' => $code,
                'name' => (string) ($fieldNames[$code] ?? $code),
                'content_selected' => $hasContent,
                'pda_selected' => $hasPda,
                'covered' => $hasContent && $hasPda,
                'missing_requirement' => $missingRequirement,
            ];
        }, $requiredCodes);

        $missing = array_values(array_map(
            fn (array $field) => [
                'code' => $field['code'],
                'name' => $field['name'],
                'content_selected' => $field['content_selected'],
                'pda_selected' => $field['pda_selected'],
                'missing_requirement' => (string) $field['missing_requirement'],
            ],
            array_filter($required, fn (array $field) => ! $field['covered']),
        ));

        return compact('required', 'missing');
    }

    /**
     * Construye opciones accionables para cada campo faltante. La unidad de
     * elección es el PDA porque al agregarlo el mapa incluye también su
     * contenido padre, de modo que una sola acción puede resolver ambos
     * requisitos sin obligar al docente a adivinar qué combinar.
     *
     * Las coincidencias de la estrategia determinista aparecen primero. Si no
     * existe coincidencia temática, se muestran opciones válidas del mismo
     * campo y grado como alternativas explícitas, sin presentarlas como
     * recomendaciones.
     *
     * @param array<string,mixed> $coverage
     * @param array<string,mixed> $suggestion
     * @param int[] $selectedContentIds
     * @param int[] $selectedPdaIds
     * @param array<string,array<int,string>> $statuses
     * @return array<string,list<array<string,mixed>>>
     */
    private function scheduleFieldOptions(
        PlanningRequest $request,
        array $coverage,
        array $suggestion,
        array $selectedContentIds,
        array $selectedPdaIds,
        array $statuses,
    ): array
    {
        $missing = $coverage['missing'] ?? [];
        if ($missing === []) {
            return [];
        }

        $missingCodes = array_values(array_unique(array_filter(array_map(
            fn (array $field) => trim((string) ($field['code'] ?? '')),
            $missing,
        ))));

        if ($missingCodes === []) {
            return [];
        }

        $contents = CurricularContent::query()
            ->with('formativeField:id,code,name')
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->whereHas('formativeField', fn ($field) => $field->whereIn('code', $missingCodes))
            ->whereHas('pdas', fn ($query) => $query
                ->where('curriculum_version_id', $request->curriculum_version_id)
                ->where('grade_id', $request->grade_id))
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'formative_field_id', 'code', 'title'])
            ->keyBy('id');

        $optionsByField = array_fill_keys($missingCodes, []);
        if ($contents->isEmpty()) {
            return $optionsByField;
        }

        $pdas = Pda::query()
            ->where('curriculum_version_id', $request->curriculum_version_id)
            ->where('grade_id', $request->grade_id)
            ->whereIn('curricular_content_id', $contents->keys()->all())
            ->orderBy('sort_order')
            ->orderBy('code')
            ->get(['id', 'curricular_content_id', 'code', 'full_text']);

        $selectedContentSet = array_fill_keys(array_map('intval', $selectedContentIds), true);
        $selectedPdaSet = array_fill_keys(array_map('intval', $selectedPdaIds), true);
        $suggestedContentOrder = array_flip(array_map('intval', $suggestion['content_ids'] ?? []));
        $suggestedPdaOrder = array_flip(array_map('intval', $suggestion['pda_ids'] ?? []));
        $rejectedContentSet = [];
        $rejectedPdaSet = [];

        foreach (($statuses['content'] ?? []) as $id => $status) {
            if ($status === 'rejected') {
                $rejectedContentSet[(int) $id] = true;
            }
        }
        foreach (($statuses['pda'] ?? []) as $id => $status) {
            if ($status === 'rejected') {
                $rejectedPdaSet[(int) $id] = true;
            }
        }

        $ranked = array_fill_keys($missingCodes, []);

        foreach ($pdas as $pda) {
            $pdaId = (int) $pda->id;
            $contentId = (int) $pda->curricular_content_id;

            if (isset($selectedPdaSet[$pdaId]) || isset($rejectedPdaSet[$pdaId]) || isset($rejectedContentSet[$contentId])) {
                continue;
            }

            $content = $contents->get($contentId);
            if (! $content) {
                continue;
            }

            $fieldCode = trim((string) ($content->formativeField?->code ?? ''));
            if ($fieldCode === '' || ! array_key_exists($fieldCode, $ranked)) {
                continue;
            }

            $suggested = array_key_exists($pdaId, $suggestedPdaOrder)
                || array_key_exists($contentId, $suggestedContentOrder);

            $ranked[$fieldCode][] = [
                'field_code' => $fieldCode,
                'content_id' => $contentId,
                'content_code' => (string) $content->code,
                'content_title' => (string) $content->title,
                'pda_id' => $pdaId,
                'pda_code' => (string) $pda->code,
                'pda_text' => (string) $pda->full_text,
                'content_already_selected' => isset($selectedContentSet[$contentId]),
                'suggested' => $suggested,
                'reason' => $suggested ? ($suggestion['reasons'][$contentId] ?? null) : null,
                '_pda_rank' => $suggestedPdaOrder[$pdaId] ?? PHP_INT_MAX,
                '_content_rank' => $suggestedContentOrder[$contentId] ?? PHP_INT_MAX,
            ];
        }

        foreach ($ranked as $fieldCode => $rows) {
            usort($rows, function (array $a, array $b): int {
                if ($a['content_already_selected'] !== $b['content_already_selected']) {
                    return $a['content_already_selected'] ? -1 : 1;
                }
                if ($a['suggested'] !== $b['suggested']) {
                    return $a['suggested'] ? -1 : 1;
                }
                if ($a['_pda_rank'] !== $b['_pda_rank']) {
                    return $a['_pda_rank'] <=> $b['_pda_rank'];
                }
                if ($a['_content_rank'] !== $b['_content_rank']) {
                    return $a['_content_rank'] <=> $b['_content_rank'];
                }

                $contentCompare = strcmp($a['content_code'], $b['content_code']);
                return $contentCompare !== 0
                    ? $contentCompare
                    : strcmp($a['pda_code'], $b['pda_code']);
            });

            $optionsByField[$fieldCode] = array_map(function (array $row): array {
                unset($row['_pda_rank'], $row['_content_rank']);
                return $row;
            }, array_slice($rows, 0, 4));
        }

        return $optionsByField;
    }

    /** @return array<string,mixed> */
    private function suggest(PlanningRequest $request): array
    {
        $request->loadMissing('planningWeeks.topics.subject');
        $requiredFieldCodes = $this->requiredScheduleFieldCodes($request);

        $baseInput = [
            'project' => $request->project,
            'topic' => $request->topic,
            'book_pages' => $request->book_pages,
            'required_activities' => $request->required_activities,
            'special_events' => $request->special_events,
            'comments' => $request->comments,
        ];

        // Cuando el horario exige campos concretos, reservamos primero espacio
        // para intentar cubrirlos y dejamos la sugerencia general como apoyo.
        $base = $this->suggestions->suggest(
            (int) $request->curriculum_version_id,
            (int) $request->grade_id,
            $baseInput,
            maxContents: $requiredFieldCodes !== [] ? 3 : 6,
        );

        if ($request->planningWeeks->isEmpty() && $requiredFieldCodes === []) {
            return $base;
        }

        $merged = [
            'strategy_version' => 'deterministic_v3_schedule_priority',
            'tokens' => [],
            'content_ids' => [],
            'pda_ids' => [],
            'axis_ids' => [],
            'formative_field_ids' => [],
            'has_strong_match' => false,
            'reasons' => [],
        ];

        $mergePiece = function (array $piece, ?string $reasonPrefix = null) use (&$merged): void {
            foreach (['tokens', 'content_ids', 'pda_ids', 'axis_ids', 'formative_field_ids'] as $listKey) {
                $merged[$listKey] = array_values(array_unique(array_merge(
                    $merged[$listKey] ?? [],
                    $piece[$listKey] ?? [],
                )));
            }

            foreach (($piece['reasons'] ?? []) as $contentId => $reason) {
                $merged['reasons'][$contentId] = ($reasonPrefix ?? '') . $reason;
            }

            $merged['has_strong_match'] = (bool) ($merged['has_strong_match'] ?? false)
                || (bool) ($piece['has_strong_match'] ?? false);
        };

        $topics = [];
        foreach ($request->planningWeeks as $week) {
            foreach ($week->topics as $topic) {
                $text = trim((string) $topic->topic);
                if ($text === '') {
                    continue;
                }

                $topics[] = [
                    'text' => $text,
                    'field_code' => trim((string) ($topic->subject?->curriculum_field_code ?? '')),
                ];
            }
        }

        // 1) Campos obligatorios del horario. Si hay temas asociados a ese campo,
        // se usan antes que el texto general de la solicitud.
        foreach ($requiredFieldCodes as $fieldCode) {
            $fieldTopicTexts = array_values(array_unique(array_map(
                fn (array $topic) => $topic['text'],
                array_filter($topics, fn (array $topic) => $topic['field_code'] === $fieldCode),
            )));

            $fieldInput = $baseInput;
            if ($fieldTopicTexts !== []) {
                $fieldInput['topic'] = implode(' ', $fieldTopicTexts);
            }

            $piece = $this->suggestions->suggest(
                (int) $request->curriculum_version_id,
                (int) $request->grade_id,
                $fieldInput,
                maxContents: 1,
                maxPdasPerContent: 2,
                maxAxes: 0,
                fieldCode: $fieldCode,
            );

            $mergePiece($piece, 'Horario ' . $fieldCode . ': ');
        }

        // 2) Temas capturados por el docente, respetando su campo cuando la
        // materia está vinculada al currículo.
        $seen = [];
        foreach ($topics as $topic) {
            $key = mb_strtolower($topic['text']) . '|' . $topic['field_code'];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $piece = $this->suggestions->suggest(
                (int) $request->curriculum_version_id,
                (int) $request->grade_id,
                ['topic' => $topic['text']],
                maxContents: 1,
                maxPdasPerContent: 2,
                maxAxes: 0,
                fieldCode: $topic['field_code'] !== '' ? $topic['field_code'] : null,
            );

            $mergePiece($piece, 'Tema “' . $topic['text'] . '”: ');
        }

        // 3) Coincidencias generales y ejes como sugerencias adicionales.
        $mergePiece($base);

        return $merged;
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
        if (! $request->canEditInputs()) {
            throw new \RuntimeException('CURRICULUM_MAP_EDITABLE_INPUT_REQUIRED');
        }
    }
}
