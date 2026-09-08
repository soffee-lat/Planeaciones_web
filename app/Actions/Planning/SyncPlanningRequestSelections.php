<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Sincroniza los pivotes de selección curricular (contents/pdas/axes) de una
 * PlanningRequest en BORRADOR. Se apoya en las FK compuestas PostgreSQL para
 * bloquear elementos de otra versión/grado; adicionalmente validamos aquí
 * para devolver un error semántico claro antes de tocar la base.
 *
 * Incrementa `selection_revision` y `input_revision` iff los pivotes cambian
 * realmente respecto al estado previo.
 */
class SyncPlanningRequestSelections
{
    /**
     * @param array{contents?:int[], pdas?:int[], axes?:int[]} $input
     */
    public function execute(User $actor, PlanningRequest $request, array $input): PlanningRequest
    {
        Gate::forUser($actor)->authorize('update', $request);

        if (! $request->isDraft()) {
            throw ValidationException::withMessages([
                'status' => 'La solicitud ya fue confirmada; no puede modificarse la selección.',
            ]);
        }

        $contentIds = array_values(array_unique(array_map('intval', $input['contents'] ?? [])));
        $pdaIds = array_values(array_unique(array_map('intval', $input['pdas'] ?? [])));
        $axisIds = array_values(array_unique(array_map('intval', $input['axes'] ?? [])));

        return DB::transaction(function () use ($request, $contentIds, $pdaIds, $axisIds) {
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($fresh->status !== PlanningRequestStatus::BORRADOR) {
                throw ValidationException::withMessages(['status' => 'La solicitud ya no está en borrador.']);
            }

            $versionId = (int) $fresh->curriculum_version_id;
            $gradeId = (int) $fresh->grade_id;

            if ($contentIds && CurricularContent::query()->whereIn('id', $contentIds)->where('curriculum_version_id', '!=', $versionId)->exists()) {
                throw ValidationException::withMessages(['contents' => 'CONTENT_VERSION_MISMATCH']);
            }
            if ($pdaIds) {
                $pdas = Pda::query()->whereIn('id', $pdaIds)->get(['id', 'curriculum_version_id', 'grade_id', 'curricular_content_id']);
                foreach ($pdas as $pda) {
                    if ((int) $pda->curriculum_version_id !== $versionId) {
                        throw ValidationException::withMessages(['pdas' => 'PDA_VERSION_MISMATCH']);
                    }
                    if ((int) $pda->grade_id !== $gradeId) {
                        throw ValidationException::withMessages(['pdas' => 'PDA_GRADE_MISMATCH']);
                    }
                }
            }
            if ($axisIds && ArticulatingAxis::query()->whereIn('id', $axisIds)->where('curriculum_version_id', '!=', $versionId)->exists()) {
                throw ValidationException::withMessages(['axes' => 'AXIS_VERSION_MISMATCH']);
            }

            $beforeContents = $fresh->contents()->pluck('curricular_contents.id')->sort()->values()->all();
            $beforePdas = $fresh->pdas()->pluck('pdas.id')->sort()->values()->all();
            $beforeAxes = $fresh->articulatingAxes()->pluck('articulating_axes.id')->sort()->values()->all();

            $afterContents = collect($contentIds)->sort()->values()->all();
            $afterPdas = collect($pdaIds)->sort()->values()->all();
            $afterAxes = collect($axisIds)->sort()->values()->all();

            $changed = ($beforeContents !== $afterContents)
                || ($beforePdas !== $afterPdas)
                || ($beforeAxes !== $afterAxes);

            if (! $changed) {
                return $fresh->refresh();
            }

            $fresh->contents()->sync(collect($contentIds)->mapWithKeys(fn ($id) => [
                $id => ['curriculum_version_id' => $versionId],
            ])->all());

            $pdaSync = [];
            foreach ($pdaIds as $id) {
                $pdaSync[$id] = [
                    'curriculum_version_id' => $versionId,
                    'grade_id' => $gradeId,
                ];
            }
            $fresh->pdas()->sync($pdaSync);

            $fresh->articulatingAxes()->sync(collect($axisIds)->mapWithKeys(fn ($id) => [
                $id => ['curriculum_version_id' => $versionId],
            ])->all());

            $fresh->selection_revision = (int) $fresh->selection_revision + 1;
            $fresh->input_revision = (int) $fresh->input_revision + 1;
            $fresh->save();

            return $fresh->refresh();
        });
    }
}
