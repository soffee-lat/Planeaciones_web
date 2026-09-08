<?php

namespace App\Actions\Planning;

use App\Actions\Commerce\ReservePlanningUnits;
use App\Enums\PlanningRequestStatus;
use App\Enums\UsageResource;
use App\Exceptions\PlanningCommercialException;
use App\Models\Group;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Commerce\CurrentCommercialRights;
use App\Services\Commerce\SubscriptionBalance;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class AuthorizePlanningRequestForProcessing
{
    public function __construct(
        private CurrentCommercialRights $rights,
        private CalculatePlanningCommercialRequirements $calculator,
        private SubscriptionBalance $balances,
        private ReservePlanningUnits $reserve,
    ) {}

    public function execute(User $actor, PlanningRequest $request): PlanningRequest
    {
        return DB::transaction(function () use ($actor, $request): PlanningRequest {
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($request->id);
            $customer = User::query()->lockForUpdate()->findOrFail($actor->id);
            Gate::forUser($customer)->authorize('authorizeProcessing', $fresh);
            if ($fresh->commercial_authorized_at !== null) {
                return $fresh;
            }
            $input = $fresh->currentInputVersion;
            if ($fresh->status !== PlanningRequestStatus::ESPERANDO_PAGO
                || ! $fresh->curriculum_confirmed_at || ! $input
                || $input->request_id !== $fresh->id || $input->snapshot !== $fresh->input_snapshot
                || ($input->snapshot['request']['starts_on'] ?? null) !== $fresh->starts_on?->toDateString()
                || ($input->snapshot['request']['ends_on'] ?? null) !== $fresh->ends_on?->toDateString()) {
                throw new PlanningCommercialException('PLANNING_REQUEST_NOT_CONFIRMED');
            }
            // Lock archived groups too: a concurrent unarchive must not invalidate the count.
            $groups = Group::query()->where('owner_id', $customer->id)->orderBy('id')->lockForUpdate()->get();
            $period = $this->rights->forCustomer($customer, lock: true);
            if (! $period) {
                throw new PlanningCommercialException('COMMERCIAL_RIGHTS_REQUIRED');
            }
            $version = $period->planVersion;
            $groupCount = $groups->whereNull('archived_at')->count();
            if ($groupCount > $period->entitlement('group_limit')) {
                throw new PlanningCommercialException('GROUP_LIMIT_EXCEEDED', $groupCount, $period->entitlement('group_limit'));
            }
            if (! $groups->whereNull('archived_at')->contains('id', $fresh->group_id)) {
                throw new PlanningCommercialException('PLANNING_GROUP_ARCHIVED');
            }
            $calculation = $this->calculator->execute($fresh, $version);
            $units = $calculation['planning_units'];
            $balance = $this->balances->forPeriod($period);
            if ($units > $balance['planning']->available()) {
                throw new PlanningCommercialException('INSUFFICIENT_PLANNING_UNITS', $units, $balance['planning']->available());
            }
            ($this->reserve)($period, UsageResource::Planning, $units, "planning-request:{$fresh->id}:planning", $fresh->id);
            $humanRequired = (bool) $period->entitlement_snapshot['human_review_required'];
            if ($humanRequired) {
                if ($units > $balance['human_review']->available()) {
                    throw new PlanningCommercialException('INSUFFICIENT_HUMAN_REVIEW_UNITS', $units, $balance['human_review']->available());
                }
                ($this->reserve)($period, UsageResource::HumanReview, $units, "planning-request:{$fresh->id}:human-review", $fresh->id);
            }
            $snapshot = [
                'schema_version' => 1,
                'plan' => ['id' => $version->plan_id, 'code' => $version->plan->code, 'name' => $version->plan->name],
                'plan_version' => ['id' => $version->id, 'number' => $version->number, 'checksum' => $version->checksum],
                'subscription' => ['id' => $period->subscription_id, 'customer_id' => $customer->id],
                'subscription_period' => ['id' => $period->id, 'starts_at' => $period->starts_at->toIso8601String(), 'ends_at' => $period->ends_at->toIso8601String()],
                'entitlements' => $period->entitlement_snapshot,
                ...$calculation,
            ];
            $fresh->segments()->createMany($calculation['segments']);
            $fresh->forceFill([
                'subscription_id' => $period->subscription_id, 'subscription_period_id' => $period->id,
                'plan_version_id' => $version->id, 'planning_days' => $calculation['planning_days'],
                'planning_units' => $units, 'calculation_strategy' => $calculation['strategy'],
                'calculation_snapshot' => $snapshot, 'correction_limit_snapshot' => $period->entitlement('correction_limit'),
                'human_review_required_snapshot' => $humanRequired, 'commercial_authorized_at' => now(),
                'status' => PlanningRequestStatus::LISTA_PARA_PROCESAR, 'lock_version' => $fresh->lock_version + 1,
            ])->save();
            $fresh->stateEvents()->create([
                'from_status' => PlanningRequestStatus::ESPERANDO_PAGO->value,
                'to_status' => PlanningRequestStatus::LISTA_PARA_PROCESAR->value,
                'actor_id' => $customer->id, 'actor_type' => 'user', 'reason' => 'commercial_authorization_reserved',
            ]);

            return $fresh->refresh();
        }, attempts: 3);
    }
}
