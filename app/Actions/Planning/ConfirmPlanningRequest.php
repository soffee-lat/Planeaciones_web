<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Models\GroupProfile;
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
    public function execute(User $actor, PlanningRequest $request): PlanningRequest
    {
        Gate::forUser($actor)->authorize('confirm', $request);

        return DB::transaction(function () use ($actor, $request) {
            /** @var PlanningRequest $fresh */
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($request->id);

            if ($fresh->status !== PlanningRequestStatus::BORRADOR) {
                throw new RuntimeException('PLANNING_REQUEST_ALREADY_CONFIRMED');
            }

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

            $fresh->fill([
                'input_revision' => $revision,
                'input_snapshot' => $snapshot,
                'current_version_id' => $inputVersion->id,
                'curriculum_confirmed_at' => now(),
                'status' => PlanningRequestStatus::ESPERANDO_PAGO,
                'lock_version' => (int) $fresh->lock_version + 1,
            ])->save();

            RequestStateEvent::query()->create([
                'request_id' => $fresh->id,
                'from_status' => PlanningRequestStatus::BORRADOR->value,
                'to_status' => PlanningRequestStatus::ESPERANDO_PAGO->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'draft_confirmed',
            ]);

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

        $contents = $r->contents()->get(['curricular_contents.id', 'code']);
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
    }

    /**
     * @return array<string,mixed>
     */
    private function buildSnapshot(PlanningRequest $r): array
    {
        $group = $r->group()->with(['school', 'profile'])->firstOrFail();
        $profile = $group->profile;

        $version = $r->curriculumVersion()->with('curriculum')->firstOrFail();
        $grade = $r->grade()->with('educationalPhase')->firstOrFail();

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

        return [
            'schema_version' => 1,
            'confirmed_at' => now()->toIso8601String(),
            'confirmed_by' => request()->user()?->id,
            'input_revision' => (int) $r->input_revision + 1,
            'selection_revision' => (int) $r->selection_revision,
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
            ],
            'curriculum' => [
                'curriculum' => [
                    'id' => $version->curriculum?->id,
                    'code' => $version->curriculum?->code,
                    'name' => $version->curriculum?->name,
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
