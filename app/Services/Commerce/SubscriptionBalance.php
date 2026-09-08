<?php

namespace App\Services\Commerce;

use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\SubscriptionPeriod;
use Illuminate\Support\Facades\DB;

/**
 * Servicio de lectura del saldo comercial de un SubscriptionPeriod.
 *
 * Sólo lectura: NUNCA muta reservas ni entitlement. Las mutaciones se
 * realizan en las Actions ReservePlanningUnits / ConsumePlanningReservation
 * / ReleasePlanningReservation.
 */
class SubscriptionBalance
{
    /**
     * @return array{planning:ResourceBalance, human_review:ResourceBalance}
     */
    public function forPeriod(SubscriptionPeriod $period): array
    {
        $planningLimit = $period->entitlement('planning_limit');
        $humanLimit = $period->entitlement('human_review_limit');

        $sums = DB::table('usage_reservations')
            ->selectRaw("resource, status, COALESCE(SUM(quantity),0) as total")
            ->where('subscription_period_id', $period->id)
            ->whereIn('resource', [UsageResource::Planning->value, UsageResource::HumanReview->value])
            ->whereIn('status', [UsageReservationStatus::Reserved->value, UsageReservationStatus::Consumed->value])
            ->groupBy('resource', 'status')
            ->get();

        $totals = [
            UsageResource::Planning->value => ['reserved' => 0, 'consumed' => 0],
            UsageResource::HumanReview->value => ['reserved' => 0, 'consumed' => 0],
        ];
        foreach ($sums as $row) {
            $totals[$row->resource][$row->status] = (int) $row->total;
        }

        return [
            'planning' => new ResourceBalance(
                UsageResource::Planning,
                $planningLimit,
                $totals[UsageResource::Planning->value]['reserved'],
                $totals[UsageResource::Planning->value]['consumed'],
            ),
            'human_review' => new ResourceBalance(
                UsageResource::HumanReview,
                $humanLimit,
                $totals[UsageResource::HumanReview->value]['reserved'],
                $totals[UsageResource::HumanReview->value]['consumed'],
            ),
        ];
    }
}
