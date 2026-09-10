<?php

namespace App\Actions\Commerce;

use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\ClientCorrectionException;
use App\Models\CorrectionRequest;
use App\Models\PlanningRequest;
use App\Models\UsageReservation;
use Illuminate\Support\Facades\DB;

final class ReserveClientCorrection
{
    public function execute(PlanningRequest $request, CorrectionRequest $correction): UsageReservation
    {
        return DB::transaction(function () use ($request, $correction): UsageReservation {
            $lockedRequest = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $lockedCorrection = CorrectionRequest::query()->whereKey($correction->id)->lockForUpdate()->firstOrFail();

            if ($lockedCorrection->type !== CorrectionRequestType::Client
                || ! in_array($lockedCorrection->status, [CorrectionRequestStatus::Requested, CorrectionRequestStatus::Accepted], true)
                || (int) $lockedCorrection->request_id !== (int) $lockedRequest->id
                || ! $lockedRequest->subscription_period_id) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_RESERVATION_STATE_INVALID');
            }

            $operationKey = sprintf('planning-request:%d:client-correction:%d', $lockedRequest->id, $lockedCorrection->id);
            $existing = UsageReservation::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->subscription_period_id !== (int) $lockedRequest->subscription_period_id
                    || (int) $existing->planning_request_id !== (int) $lockedRequest->id
                    || (int) $existing->correction_request_id !== (int) $lockedCorrection->id
                    || $existing->resource !== UsageResource::ClientCorrection
                    || (int) $existing->quantity !== 1) {
                    throw new ClientCorrectionException('CLIENT_CORRECTION_RESERVATION_IDEMPOTENCY_CONFLICT');
                }

                return $existing;
            }

            $limit = (int) $lockedRequest->correction_limit_snapshot;
            $used = (int) UsageReservation::query()
                ->where('planning_request_id', $lockedRequest->id)
                ->where('resource', UsageResource::ClientCorrection->value)
                ->whereIn('status', [UsageReservationStatus::Reserved->value, UsageReservationStatus::Consumed->value])
                ->sum('quantity');
            if ($limit < 1) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_NOT_INCLUDED');
            }
            if ($used >= $limit) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_LIMIT_REACHED');
            }

            return UsageReservation::query()->create([
                'subscription_period_id' => $lockedRequest->subscription_period_id,
                'planning_request_id' => $lockedRequest->id,
                'correction_request_id' => $lockedCorrection->id,
                'resource' => UsageResource::ClientCorrection->value,
                'operation_key' => $operationKey,
                'quantity' => 1,
                'status' => UsageReservationStatus::Reserved->value,
                'reserved_at' => now(),
            ]);
        }, attempts: 3);
    }
}
