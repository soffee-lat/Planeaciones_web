<?php

namespace App\Services\Review;

use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewerProfileStatus;
use App\Enums\RoleCode;
use App\Models\PlanningRequest;
use App\Models\ReviewerAssignment;
use App\Models\ReviewerProfile;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class ReviewerCapacityService
{
    public function dueAt(PlanningRequest $request): ?CarbonImmutable
    {
        if ($request->due_at) {
            return CarbonImmutable::instance($request->due_at);
        }

        $slaHours = (int) data_get($request->calculation_snapshot, 'entitlements.sla_hours', 0);

        return $slaHours > 0 ? CarbonImmutable::instance(now())->addHours($slaHours) : null;
    }

    /**
     * Lock all potentially eligible profiles in deterministic order before
     * calculating capacity. This serializes concurrent assignments to the same
     * reviewer without relying on a stale aggregate.
     *
     * @return Collection<int,array{profile:ReviewerProfile,active_units:int,daily_units:int,last_assigned_at:?string}>
     */
    public function eligibleLocked(PlanningRequest $request, ?int $excludeReviewerId = null): Collection
    {
        $assignedAt = now();
        $dueAt = $this->dueAt($request) ?? CarbonImmutable::instance($assignedAt);

        $profiles = ReviewerProfile::query()
            ->where('status', ReviewerProfileStatus::Active->value)
            ->whereHas('user', function ($query): void {
                $query->where('status', 'active')
                    ->whereNotNull('email_verified_at')
                    ->whereHas('roles', fn ($roles) => $roles->where('code', RoleCode::Reviewer->value));
            })
            ->whereExists(function ($query) use ($request): void {
                $query->selectRaw('1')
                    ->from('reviewer_grades')
                    ->whereColumn('reviewer_grades.reviewer_id', 'reviewer_profiles.user_id')
                    ->where('reviewer_grades.grade_id', $request->grade_id)
                    ->where('reviewer_grades.curriculum_version_id', $request->curriculum_version_id);
            })
            ->whereExists(function ($query) use ($assignedAt, $dueAt): void {
                $query->selectRaw('1')
                    ->from('reviewer_availability')
                    ->whereColumn('reviewer_availability.reviewer_id', 'reviewer_profiles.user_id')
                    ->where('reviewer_availability.starts_at', '<=', $assignedAt)
                    ->where('reviewer_availability.ends_at', '>=', $dueAt);
            })
            ->orderBy('user_id')
            ->lockForUpdate()
            ->get();

        $today = CarbonImmutable::instance($assignedAt)->toDateString();
        $rows = collect();

        foreach ($profiles as $profile) {
            if ($excludeReviewerId !== null && (int) $profile->user_id === $excludeReviewerId) {
                continue;
            }

            $activeUnits = (int) ReviewerAssignment::query()
                ->where('reviewer_id', $profile->user_id)
                ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
                ->sum('units_snapshot');

            $dailyUnits = (int) ReviewerAssignment::query()
                ->where('reviewer_id', $profile->user_id)
                ->whereDate('assigned_at', $today)
                ->where(function ($query): void {
                    $query->where('status', '!=', ReviewAssignmentStatus::Cancelled->value)
                        ->orWhereNotNull('started_at');
                })
                ->sum('units_snapshot');

            if ($activeUnits + (int) $request->planning_units > (int) $profile->max_load
                || $dailyUnits + (int) $request->planning_units > (int) $profile->daily_max) {
                continue;
            }

            $lastAssignedAt = ReviewerAssignment::query()
                ->where('reviewer_id', $profile->user_id)
                ->max('assigned_at');

            $rows->push([
                'profile' => $profile,
                'active_units' => $activeUnits,
                'daily_units' => $dailyUnits,
                'last_assigned_at' => $lastAssignedAt,
            ]);
        }

        return $rows->sort(function (array $a, array $b): int {
            $left = $a['active_units'] * max(1, (int) $b['profile']->max_load);
            $right = $b['active_units'] * max(1, (int) $a['profile']->max_load);
            if ($left !== $right) {
                return $left <=> $right;
            }

            $aLast = $a['last_assigned_at'];
            $bLast = $b['last_assigned_at'];
            if ($aLast !== $bLast) {
                if ($aLast === null) return -1;
                if ($bLast === null) return 1;
                return strcmp($aLast, $bLast);
            }

            return (int) $a['profile']->user_id <=> (int) $b['profile']->user_id;
        })->values();
    }
}
