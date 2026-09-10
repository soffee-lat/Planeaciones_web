<?php

namespace App\Services\Review;

use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewerWorkItemStatus;
use App\Models\ReviewerAssignment;
use App\Models\ReviewerWorkItem;
use App\Models\User;

final class ReviewerMetricsService
{
    /** @return array{active_units:int,completed_assignments:int,payable_items:int,paid_items:int,amounts:array<string,array{approved_minor:int,paid_minor:int}>} */
    public function snapshot(User $reviewer): array
    {
        $amounts = ReviewerWorkItem::query()
            ->where('reviewer_id', $reviewer->id)
            ->selectRaw("currency, SUM(CASE WHEN status = 'approved' THEN total_minor ELSE 0 END) AS approved_minor, SUM(CASE WHEN status = 'paid' THEN total_minor ELSE 0 END) AS paid_minor")
            ->groupBy('currency')
            ->get()
            ->mapWithKeys(fn ($row): array => [(string) $row->currency => [
                'approved_minor' => (int) $row->approved_minor,
                'paid_minor' => (int) $row->paid_minor,
            ]])
            ->all();

        return [
            'active_units' => (int) ReviewerAssignment::query()
                ->where('reviewer_id', $reviewer->id)
                ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
                ->sum('units_snapshot'),
            'completed_assignments' => ReviewerAssignment::query()
                ->where('reviewer_id', $reviewer->id)
                ->where('status', ReviewAssignmentStatus::Completed->value)
                ->count(),
            'payable_items' => ReviewerWorkItem::query()
                ->where('reviewer_id', $reviewer->id)
                ->where('status', ReviewerWorkItemStatus::Approved->value)
                ->count(),
            'paid_items' => ReviewerWorkItem::query()
                ->where('reviewer_id', $reviewer->id)
                ->where('status', ReviewerWorkItemStatus::Paid->value)
                ->count(),
            'amounts' => $amounts,
        ];
    }
}
