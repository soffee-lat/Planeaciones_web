<?php

namespace App\Actions\Planning;

use App\Enums\CorrectionRequestStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\ClientCorrectionException;
use App\Models\CorrectionRequest;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class WithdrawClientCorrection
{
    public function __construct(private PlanningRequestStateMachine $stateMachine) {}

    public function execute(CorrectionRequest|int $correction, User $actor, ?string $correlationId = null): CorrectionRequest
    {
        $correctionId = $correction instanceof CorrectionRequest ? $correction->id : $correction;
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        return DB::transaction(function () use ($actor, $correctionId, $correlationId): CorrectionRequest {
            $lockedCorrection = CorrectionRequest::query()->whereKey($correctionId)->lockForUpdate()->firstOrFail();
            $request = PlanningRequest::query()->whereKey($lockedCorrection->request_id)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('requestCorrection', $request);

            if ($lockedCorrection->status === CorrectionRequestStatus::Withdrawn) {
                return $lockedCorrection;
            }
            if ($lockedCorrection->status !== CorrectionRequestStatus::Requested
                || $request->status !== PlanningRequestStatus::CORRECCION_SOLICITADA
                || (int) $lockedCorrection->requester_id !== (int) $actor->id) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_WITHDRAW_STATE_INVALID');
            }

            $returnStatus = $this->returnStatus($request);
            $now = now();
            $lockedCorrection->forceFill([
                'status' => CorrectionRequestStatus::Withdrawn->value,
                'resolved_at' => $now,
                'resolution' => 'withdrawn_by_customer',
            ])->save();

            $this->stateMachine->assertCanTransition($request->status, $returnStatus);
            $request->forceFill([
                'status' => $returnStatus->value,
                'lock_version' => (int) $request->lock_version + 1,
            ])->save();
            $request->stateEvents()->create([
                'from_status' => PlanningRequestStatus::CORRECCION_SOLICITADA->value,
                'to_status' => $returnStatus->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'client_correction_withdrawn',
                'correlation_id' => $correlationId,
            ]);

            return $lockedCorrection->fresh();
        }, attempts: 3);
    }

    private function returnStatus(PlanningRequest $request): PlanningRequestStatus
    {
        $event = $request->stateEvents()
            ->where('to_status', PlanningRequestStatus::CORRECCION_SOLICITADA->value)
            ->where('reason', 'client_correction_requested')
            ->latest('id')
            ->first();
        $status = $event ? PlanningRequestStatus::tryFrom((string) $event->from_status) : null;
        if (! in_array($status, [PlanningRequestStatus::ENTREGADA, PlanningRequestStatus::COMPLETADA], true)) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_RETURN_STATE_INVALID');
        }

        return $status;
    }
}
