<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Models\GroupProfile;
use App\Services\Planning\PlanningCalendarBuilder;
use App\Services\Planning\PlanningFocusResolver;
use App\Services\Pedagogy\PedagogicalStageProfile;
use App\Services\Pedagogy\SupportedEducationalScope;
use App\Services\AI\RequestBlockManager;
use App\Models\PlanningRequest;
use App\Models\RequestInputVersion;
use App\Models\RequestStateEvent;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use RuntimeException;

/**
 * Confirma una PlanningRequest en BORRADOR y congela un snapshot textual
 * completo (JSONB) que incluye datos de la solicitud, del grupo/perfil
 * pedagógico y del árbol curricular seleccionado (con textos completos,
 * no sólo IDs).
 *
 * En Subfase 2C, tras confirmar, la solicitud transita a ESPERANDO_PAGO
 * porque el módulo comercial (planes/periodos/reservas) aún no existe;
 * la transición a LISTA_PARA_PROCESAR requiere derechos y es responsabilidad
 * de la Fase 3. Ver WORKFLOWS.md matriz "BORRADOR → ESPERANDO_PAGO".
 *
 * Códigos de error semánticos:
 *  - PLANNING_REQUEST_ALREADY_CONFIRMED
 *  - PLANNING_REQUEST_MISSING_DATES
 *  - PLANNING_REQUEST_MISSING_TOPIC
 *  - PLANNING_REQUEST_NO_CONTENT_SELECTED
 *  - PLANNING_REQUEST_NO_PDA_SELECTED
 *  - PLANNING_REQUEST_CONTENT_WITHOUT_PDA:<code>
 *  - PLANNING_REQUEST_GROUP_PROFILE_INSUFFICIENT
 */
class ConfirmPlanningRequest
{
    public function __construct(
        private PlanningCalendarBuilder $calendarBuilder,
        private PlanningFocusResolver $focusResolver,
        private RequestBlockManager $blocks,
        private PedagogicalStageProfile $pedagogicalStage,
        private SupportedEducationalScope $educationalScope,
    ) {}

    public function execute(User $actor, PlanningRequest $request): PlanningRequest
    {
        Gate::forUser($actor)->authorize('confirm', $request);

        return DB::transaction(function () use ($actor, $request) {
            /** @var PlanningRequest $fresh */
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($request->id);

            if (! $fresh->canEditInputs()) {
                throw new RuntimeException('PLANNING_REQUEST_INPUTS_NOT_EDITABLE');
            }

            $fromStatus = $fresh->status;
            $isInputRevision = $fromStatus === PlanningRequestStatus::ESPERANDO_INFORMACION;
            $this->assertReady($fresh);

            $snapshot = $this->buildSnapshot($fresh);

            $revision = (int) $fresh->input_revision + 1;
            $inputVersion = RequestInputVersion::query()->create([
                'request_id' => $fresh->id,
                'revision' => $revision,
                'snapshot' => $snapshot,
                'created_by' => $actor->id,
                'reason' => 'confirmation',
            ]);

            $toStatus = $fresh->commercial_authorized_at !== null
                ? PlanningRequestStatus::LISTA_PARA_PROCESAR
                : PlanningRequestStatus::ESPERANDO_PAGO;

            $fresh->fill([
                'input_revision' => $revision,
                'input_snapshot' => $snapshot,
                'current_version_id' => $inputVersion->id,
                'curriculum_confirmed_at' => now(),
                'status' => $toStatus,
                'lock_version' => (int) $fresh->lock_version + 1,
            ])->save();

            RequestStateEvent::query()->create([
                'request_id' => $fresh->id,
                'from_status' => $fromStatus->value,
                'to_status' => $toStatus->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => $isInputRevision ? 'input_revision_confirmed' : 'draft_confirmed',
            ]);

            if ($isInputRevision) {
                $this->blocks->resolve($fresh, 'ai_quality_attention', 'audit', $actor);
            }

            return $fresh->refresh();
        });
    }

    private function assertReady(PlanningRequest $r): void
    {
        if (! $r->starts_on || ! $r->ends_on) {
            throw new RuntimeException('PLANNING_REQUEST_MISSING_DATES');
        }
        if (! trim((string) ($r->project ?? '')) && ! trim((string) ($r->topic ?? ''))) {
            throw new RuntimeException('PLANNING_REQUEST_MISSING_TOPIC');
        }

        $group = $r->group()->with('profile')->firstOrFail();
        $profile = $group->profile;
        if (! $profile || ! $profile->isSufficient()) {
            throw new RuntimeException('PLANNING_REQUEST_GROUP_PROFILE_INSUFFICIENT');
        }

        $contents = $r->contents()
            ->with('formativeField:id,code,name')
            ->get(['curricular_contents.id', 'curricular_contents.formative_field_id', 'code']);
        if ($contents->isEmpty()) {
            throw new RuntimeException('PLANNING_REQUEST_NO_CONTENT_SELECTED');
        }
        $pdas = $r->pdas()->get(['pdas.id', 'curricular_content_id', 'code']);
        if ($pdas->isEmpty()) {
            throw new RuntimeException('PLANNING_REQUEST_NO_PDA_SELECTED');
        }
        $pdaContentIds = $pdas->pluck('curricular_content_id')->unique()->all();
        foreach ($contents as $c) {
            if (! in_array($c->id, $pdaContentIds, true)) {
                throw new RuntimeException('PLANNING_REQUEST_CONTENT_WITHOUT_PDA:' . $c->code);
            }
        }

        $selectedFieldCodes = $contents
            ->map(fn ($content) => (string) ($content->formativeField?->code ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $groupWithSchedule = $r->group()->with('activeSchedule.blocks')->firstOrFail();
        $requiredFieldCodes = [];
        foreach ($groupWithSchedule->activeSchedule?->blocks ?? [] as $block) {
            if (! $block->include_in_planning || $block->is_flexible) {
                continue;
            }
            foreach ((array) ($block->field_codes ?? []) as $fieldCode) {
                $fieldCode = trim((string) $fieldCode);
                if ($fieldCode !== '') {
                    $requiredFieldCodes[$fieldCode] = true;
                }
            }
        }

        $missingFieldCodes = array_values(array_diff(array_keys($requiredFieldCodes), $selectedFieldCodes));
        sort($missingFieldCodes, SORT_STRING);
        if ($missingFieldCodes !== []) {
            throw new RuntimeException(
                'PLANNING_REQUEST_SCHEDULE_FIELD_NOT_SELECTED:' . implode(',', $missingFieldCodes)
            );
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSnapshot(PlanningRequest $r): array
    {
        $r->loadMissing('planningWeeks.topics.subject');
        $group = $r->group()->with(['school', 'profile', 'activeSchedule.blocks'])->firstOrFail();
        $profile = $group->profile;
        $schedule = $group->activeSchedule;
        $scheduleSnapshot = null;
        $planningCalendar = [];

        if ($schedule) {
            $scheduleSnapshot = [
                'id' => (int) $schedule->id,
                'revision' => (int) $schedule->revision,
                'name' => $schedule->name,
                'day_starts_at' => $schedule->day_starts_at ? substr((string) $schedule->day_starts_at, 0, 5) : null,
                'day_ends_at' => $schedule->day_ends_at ? substr((string) $schedule->day_ends_at, 0, 5) : null,
                'valid_from' => $schedule->valid_from?->format('Y-m-d'),
                'valid_until' => $schedule->valid_until?->format('Y-m-d'),
                'blocks' => $schedule->blocks->map(fn ($block) => [
                    'id' => (int) $block->id,
                    'day_of_week' => (int) $block->day_of_week,
                    'sequence' => (int) $block->sequence,
                    'starts_at' => substr((string) $block->starts_at, 0, 5),
                    'ends_at' => substr((string) $block->ends_at, 0, 5),
                    'label' => $block->label,
                    'group_subject_id' => $block->group_subject_id ? (int) $block->group_subject_id : null,
                    'subject_name_snapshot' => $block->subject_name_snapshot,
                    'subject_color_snapshot' => $block->subject_color_snapshot,
                    'block_type' => $block->block_type,
                    'responsibility' => $block->responsibility,
                    'include_in_planning' => (bool) $block->include_in_planning,
                    'is_flexible' => (bool) $block->is_flexible,
                    'field_codes' => array_values($block->field_codes ?? []),
                    'notes' => $block->notes,
                ])->values()->all(),
            ];

            $planningCalendar = $this->calendarBuilder->build(
                $schedule,
                $r->starts_on->format('Y-m-d'),
                $r->ends_on->format('Y-m-d'),
            );
            $planningCalendar = $this->focusResolver->enrich($planningCalendar, $r->planningWeeks);
        }

        $version = $r->curriculumVersion()->with('curriculum')->firstOrFail();
        $grade = $r->grade()->with('educationalPhase')->firstOrFail();
        if ($version->curriculum) {
            $this->educationalScope->assert($version->curriculum, $grade);
        }
        $stageProfile = $version->curriculum
            ? $this->pedagogicalStage->for($version->curriculum, $grade)
            : null;

        $contents = $r->contents()->with(['educationalPhase', 'formativeField'])->orderBy('sort_order')->get();
        $pdas = $r->pdas()->orderBy('sort_order')->get();
        $axes = $r->articulatingAxes()->orderBy('sort_order')->get();

        $fieldsById = [];
        foreach ($contents as $c) {
            if ($c->formativeField) {
                $fieldsById[$c->formativeField->id] = [
                    'id' => $c->formativeField->id,
                    'code' => $c->formativeField->code,
                    'name' => $c->formativeField->name,
                ];
            }
        }

        $profileFields = [
            'revision' => $profile ? (int) $profile->revision : 0,
        ];
        if ($profile) {
            foreach (GroupProfile::PEDAGOGICAL_FIELDS as $f) {
                $profileFields[$f] = $profile->getAttribute($f);
            }
        }

        $pedagogicalStructure = $r->planningWeeks->isEmpty() ? null : [
            'period_type' => $r->period_type,
            'period_key' => $r->period_key,
            'period_label' => $r->period_label,
            'integrative_project' => $r->integrative_project ? [
                'name' => $r->integrative_project,
                'purpose' => $r->integrative_project_purpose,
            ] : null,
            'weeks' => $r->planningWeeks->map(fn ($week) => [
                'sequence' => (int) $week->sequence,
                'starts_on' => $week->starts_on?->toDateString(),
                'ends_on' => $week->ends_on?->toDateString(),
                'label' => $week->label,
                'topics' => $week->topics->map(fn ($topic) => [
                    'topic' => $topic->topic,
                    'notes' => $topic->notes,
                    'group_subject_id' => $topic->group_subject_id ? (int) $topic->group_subject_id : null,
                    'subject_name' => $topic->subject?->name,
                    'subject_color' => $topic->subject?->color,
                ])->values()->all(),
            ])->values()->all(),
        ];

        return [
            'schema_version' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'confirmed_by' => request()->user()?->id,
            'input_revision' => (int) $r->input_revision + 1,
            'selection_revision' => (int) $r->selection_revision,
            'pedagogical_structure' => $pedagogicalStructure,
            'pedagogical_stage' => $stageProfile,
            'request' => [
                'id' => $r->id,
                'starts_on' => $r->starts_on?->format('Y-m-d'),
                'ends_on' => $r->ends_on?->format('Y-m-d'),
                'period_label' => $r->period_label,
                'project' => $r->project,
                'topic' => $r->topic,
                'book_pages' => $r->book_pages,
                'required_activities' => $r->required_activities,
                'special_events' => $r->special_events,
                'comments' => $r->comments,
                'pedagogical_notes' => $r->pedagogical_notes,
                'suggested_initial_assessment' => $r->suggested_initial_assessment,
                'requested_assessment' => $r->requested_assessment,
                'creation_mode' => $r->creation_mode,
            ],
            'group' => [
                'id' => $group->id,
                'name' => $group->name,
                'school_year' => $group->school_year,
                'school' => $group->school ? [
                    'id' => $group->school->id,
                    'name' => $group->school->name,
                    'school_type' => $group->school->school_type instanceof \BackedEnum
                        ? $group->school->school_type->value
                        : $group->school->school_type,
                    'state' => $group->school->state,
                    'municipality' => $group->school->municipality,
                ] : null,
                'profile' => $profileFields,
                'schedule' => $scheduleSnapshot,
                'planning_calendar' => $planningCalendar,
            ],
            'curriculum' => [
                'curriculum' => [
                    'id' => $version->curriculum?->id,
                    'code' => $version->curriculum?->code,
                    'name' => $version->curriculum?->name,
                    'educational_level' => $version->curriculum?->educational_level,
                    'educational_level_label' => $version->curriculum?->educationalLevelLabel(),
                ],
                'version' => [
                    'id' => $version->id,
                    'number' => $version->number,
                    'label' => $version->label,
                    'checksum' => $version->checksum,
                    'published_at' => $version->published_at?->toIso8601String(),
                ],
                'phase' => $grade->educationalPhase ? [
                    'id' => $grade->educationalPhase->id,
                    'code' => $grade->educationalPhase->code,
                    'name' => $grade->educationalPhase->name,
                ] : null,
                'grade' => [
                    'id' => $grade->id,
                    'code' => $grade->code,
                    'name' => $grade->name,
                    'ordinal' => $grade->ordinal,
                ],
                'formative_fields' => array_values($fieldsById),
                'contents' => $contents->map(fn ($c) => [
                    'id' => $c->id,
                    'code' => $c->code,
                    'title' => $c->title,
                    'full_text' => $c->full_text,
                    'phase_code' => $c->educationalPhase?->code,
                    'field_code' => $c->formativeField?->code,
                ])->all(),
                'pdas' => $pdas->map(fn ($p) => [
                    'id' => $p->id,
                    'code' => $p->code,
                    'full_text' => $p->full_text,
                    'content_id' => $p->curricular_content_id,
                    'grade_id' => $p->grade_id,
                ])->all(),
                'axes' => $axes->map(fn ($a) => [
                    'id' => $a->id,
                    'code' => $a->code,
                    'name' => $a->name,
                ])->all(),
            ],
        ];
    }
}
