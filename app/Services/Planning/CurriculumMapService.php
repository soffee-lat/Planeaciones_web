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

        // Si el docente agrega un PDA, conservamos su contenido padre para que
        // la selección siga siendo curricularmente íntegra. Lo contrario no es
        // obligatorio: un contenido puede ser pertinente aunque no exista un
        // PDA adecuado para ese tema concreto.
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
        $selectedContentIds = $this->sortedInts($selected['contents']);

        // La selección curricular es una ayuda, no un requisito artificial. Un
        // docente puede continuar sin contenidos/PDA cuando no existe una
        // correspondencia real para el tema (por ejemplo Inglés, Tecnología,
        // Deportes o Huerto). Si sí eligió un PDA, su contenido padre debe
        // permanecer seleccionado para conservar integridad referencial.
        $pdaContentIds = $selected['pdas'] === []
            ? []
            : Pda::query()
                ->whereIn('id', $selected['pdas'])
                ->pluck('curricular_content_id')
                ->map(fn ($id) => (int) $id)
                ->unique()
                ->values()
                ->all();

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
     * Devuelve los códigos de campo oficial asociados a bloques no flexibles
     * que participan en la planeación. Se usan para orientar sugerencias, no
     * para obligar al docente a seleccionar una referencia curricular.
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
     * Resume la cobertura curricular disponible por campo. Es información de
     * apoyo para la interfaz; una ausencia ya no bloquea la planeación porque
     * puede significar que no existe una correspondencia temática real.
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
            $covered = $hasContent || $hasPda;

            return [
                'code' => $code,
                'name' => (string) ($fieldNames[$code] ?? $code),
                'content_selected' => $hasContent,
                'pda_selected' => $hasPda,
                'covered' => $covered,
                'missing_requirement' => $covered ? null : 'optional_curriculum',
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
     * Construye opciones sólo cuando la estrategia encontró una coincidencia
     * temática real. Ya no rellena campos faltantes con PDA arbitrarios del
     * mismo campo únicamente para completar el formulario.
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
            if (! $suggested) {
                continue;
            }

            $ranked[$fieldCode][] = [
                'field_code' => $fieldCode,
                'content_id' => $contentId,
                'content_code' => (string) $content->code,
                'content_title' => (string) $content->title,
                'pda_id' => $pdaId,
                'pda_code' => (string) $pda->code,
                'pda_text' => (string) $pda->full_text,
                'content_already_selected' => isset($selectedContentSet[$contentId]),
                'suggested' => true,
                'reason' => $suggestion['reasons'][$contentId] ?? null,
                '_pda_rank' => $suggestedPdaOrder[$pdaId] ?? PHP_INT_MAX,
                '_content_rank' => $suggestedContentOrder[$contentId] ?? PHP_INT_MAX,
            ];
        }

        foreach ($ranked as $fieldCode => $rows) {
            usort($rows, function (array $a, array $b): int {
                if ($a['content_already_selected'] !== $b['content_already_selected']) {
                    return $a['content_already_selected'] ? -1 : 1;
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

        $base = $this->suggestions->suggest(
            (int) $request->curriculum_version_id,
            (int) $request->grade_id,
            $baseInput,
            maxContents: 3,
        );

        $merged = [
            'strategy_version' => 'deterministic_v4_topic_strict',
            'tokens' => [],
            'content_ids' => [],
            'pda_ids' => [],
            'axis_ids' => [],
            'formative_field_ids' => [],
            'has_strong_match' => false,
            'reasons' => [],
        ];

        $mergeStrongCurriculum = function (array $piece, ?string $reasonPrefix = null) use (&$merged): void {
            $strong = (bool) ($piece['has_strong_match'] ?? false);
            $merged['tokens'] = array_values(array_unique(array_merge($merged['tokens'], $piece['tokens'] ?? [])));
            if (! $strong) {
                return;
            }

            foreach (['content_ids', 'pda_ids', 'formative_field_ids'] as $listKey) {
                $merged[$listKey] = array_values(array_unique(array_merge(
                    $merged[$listKey],
                    $piece[$listKey] ?? [],
                )));
            }
            foreach (($piece['reasons'] ?? []) as $contentId => $reason) {
                $merged['reasons'][$contentId] = ($reasonPrefix ?? '') . $reason;
            }
            $merged['has_strong_match'] = true;
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

        // Los ejes pueden surgir del contexto global. Contenidos y PDA, en
        // cambio, se vinculan sólo desde temas de materias que sí tienen un
        // campo curricular configurado y sólo cuando la coincidencia es fuerte.
        $merged['tokens'] = array_values(array_unique(array_merge($merged['tokens'], $base['tokens'] ?? [])));
        $merged['axis_ids'] = array_values(array_unique(array_map('intval', $base['axis_ids'] ?? [])));

        if ($topics === [] && $requiredFieldCodes === []) {
            $mergeStrongCurriculum($base);
            return $merged;
        }

        // Primero agrupamos los temas por campo. Esto evita usar el texto global
        // de toda la semana para justificar un PDA de una materia distinta.
        $topicsByField = [];
        foreach ($topics as $topic) {
            if ($topic['field_code'] === '') {
                continue;
            }
            $topicsByField[$topic['field_code']][] = $topic['text'];
        }

        foreach ($topicsByField as $fieldCode => $fieldTopics) {
            $fieldTopics = array_values(array_unique($fieldTopics));
            $piece = $this->suggestions->suggest(
                (int) $request->curriculum_version_id,
                (int) $request->grade_id,
                ['topic' => implode(' ', $fieldTopics)],
                maxContents: 2,
                maxPdasPerContent: 2,
                maxAxes: 0,
                fieldCode: $fieldCode,
            );

            $mergeStrongCurriculum($piece, 'Temas del campo: ');
        }

        // Si el horario declara un campo curricular pero no hay un tema docente
        // asociado a él, no inventamos una selección a partir del resto de la
        // solicitud. La ausencia queda como información opcional en la interfaz.
        foreach ($requiredFieldCodes as $fieldCode) {
            if (array_key_exists($fieldCode, $topicsByField)) {
                continue;
            }
        }

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
