<?php

namespace App\Actions\Review;

use App\Actions\Notifications\QueueOperationalNotification;
use App\Enums\OperationalNotificationType;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Exceptions\ReviewerAssignmentException;
use App\Models\PlanningRequest;
use App\Models\ReviewerAssignment;
use App\Models\User;
use App\Services\AI\RequestBlockManager;
use App\Services\Review\ReviewerCapacityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class ReassignReviewer
{
    public function __construct(
        private ReviewerCapacityService $capacity,
        private RequestBlockManager $blocks,
        private QueueOperationalNotification $notifications,
    ) {}

    public function execute(
        User $actor,
        PlanningRequest $request,
        string $reason,
        ?string $correlationId = null,
    ): ?ReviewerAssignment {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new ReviewerAssignmentException('REVIEW_REASSIGNMENT_ADMIN_REQUIRED');
        }
        $reason = trim($reason);
        if ($reason === '') {
            throw new ReviewerAssignmentException('REVIEW_REASSIGNMENT_REASON_REQUIRED');
        }
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new ReviewerAssignmentException('REVIEW_ASSIGNMENT_CORRELATION_INVALID');
        }

        return DB::transaction(function () use ($actor, $request, $reason, $correlationId): ?ReviewerAssignment {
            $fresh = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($fresh->status !== PlanningRequestStatus::REVISION_HUMANA) {
                throw new ReviewerAssignmentException('REVIEW_REASSIGNMENT_REQUEST_STATE_INVALID');
            }

            $current = ReviewerAssignment::query()
                ->where('request_id', $fresh->id)
                ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
                ->lockForUpdate()
                ->first();
            if (! $current) {
                throw new ReviewerAssignmentException('REVIEW_REASSIGNMENT_ACTIVE_ASSIGNMENT_REQUIRED');
            }

            $candidate = $this->capacity->eligibleLocked($fresh, (int) $current->reviewer_id)->first();
            if (! $candidate) {
                $this->blocks->open($fresh, 'no_reviewer', 'human_review', [
                    'reason' => 'reassignment_no_candidate',
                    'previous_reviewer_id' => (int) $current->reviewer_id,
                    'planning_units' => (int) $fresh->planning_units,
                ], strtolower($correlationId));

                return null;
            }

            $current->forceFill([
                'status' => ReviewAssignmentStatus::Reassigned->value,
                'ended_at' => now(),
                'ended_by' => $actor->id,
                'ended_reason' => $reason,
            ])->save();

            $profile = $candidate['profile'];
            $replacement = ReviewerAssignment::query()->create([
                'request_id' => $fresh->id,
                'reviewer_id' => $profile->user_id,
                'cycle' => (int) $current->cycle,
                'status' => ReviewAssignmentStatus::Assigned->value,
                'due_at' => $current->due_at,
                'assigned_at' => now(),
                'started_at' => null,
                'ended_at' => null,
                'ended_by' => null,
                'ended_reason' => null,
                'rate_snapshot_minor' => (int) $profile->rate_minor,
                'units_snapshot' => (int) $fresh->planning_units,
                'total_fee_minor' => (int) $profile->rate_minor * (int) $fresh->planning_units,
                'currency' => $profile->currency,
            ]);

            $this->blocks->resolve($fresh, 'no_reviewer', 'human_review', $actor);

            $reviewer = User::query()->findOrFail($replacement->reviewer_id);
            $this->notifications->execute(
                OperationalNotificationType::ReviewAssigned,
                $reviewer,
                "review-assignment:{$replacement->id}:assigned",
                'review_assignment',
                (int) $replacement->id,
                [
                    'title' => 'Tienes una nueva revisión',
                    'body' => 'Se te asignó una planeación para revisión humana.',
                    'url' => '/review/assignments/' . $replacement->id,
                ],
            );

            return $replacement->fresh(['reviewer', 'profile']);
        }, attempts: 3);
    }
}
