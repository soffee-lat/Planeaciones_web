<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * Actualiza campos editables de una PlanningRequest en BORRADOR e incrementa
 * `input_revision` únicamente cuando al menos un campo trackeado cambia
 * realmente (misma filosofía que UpdateGroupProfile.revision).
 *
 * NO toca selecciones curriculares (eso lo hace SyncPlanningRequestSelections).
 * NO cambia el estado; la confirmación es una acción explícita separada.
 */
class UpdatePlanningRequestDraft
{
    /** @var list<string> */
    private const NULLABLE_TEXT_FIELDS = [
        'period_label',
        'project',
        'topic',
        'book_pages',
        'required_activities',
        'special_events',
        'comments',
        'pedagogical_notes',
        'suggested_initial_assessment',
        'requested_assessment',
    ];

    public function execute(User $actor, PlanningRequest $request, array $input): PlanningRequest
    {
        Gate::forUser($actor)->authorize('update', $request);

        if (! $request->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'La solicitud ya fue confirmada; no puede editarse como borrador.',
            ]);
        }

        $data = Validator::make($input, [
            'creation_mode' => ['nullable', 'string', 'in:quick,advanced'],
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'period_label' => ['nullable', 'string', 'max:64'],
            'project' => ['nullable', 'string', 'max:255'],
            'topic' => ['nullable', 'string', 'max:8000'],
            'book_pages' => ['nullable', 'string', 'max:8000'],
            'required_activities' => ['nullable', 'string', 'max:8000'],
            'special_events' => ['nullable', 'string', 'max:8000'],
            'comments' => ['nullable', 'string', 'max:8000'],
            'pedagogical_notes' => ['nullable', 'string', 'max:8000'],
            'suggested_initial_assessment' => ['nullable', 'string', 'max:8000'],
            'requested_assessment' => ['nullable', 'string', 'max:8000'],
        ])->validate();

        // Filament hidrata TextInput/Textarea vacíos como "" aunque PostgreSQL
        // conserve NULL. Ambos representan ausencia de dato y no deben provocar
        // una revisión falsa ni invalidar un mapa curricular recién confirmado.
        foreach (self::NULLABLE_TEXT_FIELDS as $field) {
            if (array_key_exists($field, $data)
                && is_string($data[$field])
                && trim($data[$field]) === '') {
                $data[$field] = null;
            }
        }

        return DB::transaction(function () use ($request, $data) {
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($fresh->status !== PlanningRequestStatus::BORRADOR) {
                throw ValidationException::withMessages(['status' => 'La solicitud ya no está en borrador.']);
            }

            $changed = false;
            $mapInputsChanged = false;
            foreach (PlanningRequest::TRACKED_INPUT_FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $newValue = $data[$field];
                $current = $fresh->getAttribute($field);
                // Normalizar comparaciones de fechas para no incrementar por casts.
                if (in_array($field, ['starts_on', 'ends_on'], true)) {
                    $currentStr = $current ? (is_string($current) ? $current : $current->format('Y-m-d')) : null;
                    $newStr = $newValue ?: null;
                    if ($currentStr !== $newStr) {
                        $fresh->setAttribute($field, $newValue);
                        $changed = true;
                    }
                    continue;
                }
                if ($current !== $newValue) {
                    $fresh->setAttribute($field, $newValue);
                    $changed = true;
                    if (in_array($field, PlanningRequest::CURRICULUM_MAP_INPUT_FIELDS, true)) {
                        $mapInputsChanged = true;
                    }
                }
            }

            if ($changed) {
                $fresh->input_revision = (int) $fresh->input_revision + 1;
            }
            if ($mapInputsChanged) {
                $fresh->curriculum_confirmed_at = null;
                $fresh->curriculum_selection_fingerprint = null;
            }
            $fresh->save();

            return $fresh->refresh();
        });
    }
}
