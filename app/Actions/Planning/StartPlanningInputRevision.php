<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\AI\RequestBlockManager;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class StartPlanningInputRevision
{
    public function __construct(
        private PlanningRequestStateMachine $stateMachine,
        private RequestBlockManager $blocks,
    ) {}

    public function execute(User $actor, PlanningRequest $request): PlanningRequest
    {
        Gate::forUser($actor)->authorize('view', $request);

        return DB::transaction(function () use ($actor, $request): PlanningRequest {
            $fresh = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === PlanningRequestStatus::ESPERANDO_INFORMACION
                && $fresh->requiresCurriculumInputRevision()) {
                return $fresh->refresh();
            }

            if ($fresh->status !== PlanningRequestStatus::AUDITORIA_IA
                || ! $fresh->requiresCurriculumInputRevision()) {
                throw ValidationException::withMessages([
                    'status' => 'Esta planeación no requiere revisar sus insumos curriculares.',
                ]);
            }

            $this->stateMachine->assertCanTransition(
                $fresh->status,
                PlanningRequestStatus::ESPERANDO_INFORMACION,
            );

            $from = $fresh->status;
            $fresh->forceFill([
                'status' => PlanningRequestStatus::ESPERANDO_INFORMACION->value,
                'curriculum_confirmed_at' => null,
                'curriculum_selection_fingerprint' => null,
                'lock_version' => (int) $fresh->lock_version + 1,
            ])->save();

            $fresh->stateEvents()->create([
                'from_status' => $from->value,
                'to_status' => PlanningRequestStatus::ESPERANDO_INFORMACION->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'audit_requested_input_revision',
            ]);

            return $fresh->refresh();
        }, attempts: 3);
    }
}
