<?php

namespace App\Actions\Review;

use App\Actions\Commerce\ConsumePlanningReservation;
use App\Actions\Notifications\QueueOperationalNotification;
use App\Enums\ApprovalKind;
use App\Enums\OperationalNotificationType;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\UsageResource;
use App\Exceptions\ReviewerAssignmentException;
use App\Models\Approval;
use App\Models\PlanningRequest;
use App\Models\ReviewerAssignment;
use App\Models\UsageReservation;
use App\Models\User;
use App\Services\AI\RequestBlockManager;
use App\Services\Review\ReviewerCapacityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class AssignReviewer
{
    public function __construct(
        private ReviewerCapacityService $capacity,
        private ConsumePlanningReservation $consume,
        private RequestBlockManager $blocks,
        private QueueOperationalNotification $notifications,
    ) {}

    public function execute(PlanningRequest $request, ?string $correlationId = null): ?ReviewerAssignment
    {
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new ReviewerAssignmentException('REVIEW_ASSIGNMENT_CORRELATION_INVALID');
        }

        return DB::transaction(function () use ($request, $correlationId): ?ReviewerAssignment {
            $fresh = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            $this->assertRequestReady($fresh);

            $existing = ReviewerAssignment::query()
                ->where('request_id', $fresh->id)
                ->whereIn('status', [ReviewAssignmentStatus::Assigned->value, ReviewAssignmentStatus::InProgress->value])
                ->lockForUpdate()
                ->first();
            if ($existing) {
                return $existing;
            }

            $candidates = $this->capacity->eligibleLocked($fresh);
            $preferredReviewerId = ReviewerAssignment::query()
                ->where('request_id', $fresh->id)
                ->where('ended_reason', 'human_review_changes_requested')
                ->orderByDesc('id')
                ->value('reviewer_id');
            $candidate = $preferredReviewerId
                ? ($candidates->first(fn (array $row): bool => (int) $row['profile']->user_id === (int) $preferredReviewerId) ?? $candidates->first())
                : $candidates->first();
            if (! $candidate) {
                $this->blocks->open($fresh, 'no_reviewer', 'human_review', [
                    'planning_units' => (int) $fresh->planning_units,
                    'grade_id' => (int) $fresh->grade_id,
                    'curriculum_version_id' => (int) $fresh->curriculum_version_id,
                ], strtolower($correlationId));

                return null;
            }

            $reservation = UsageReservation::query()
                ->where('planning_request_id', $fresh->id)
                ->where('resource', UsageResource::HumanReview->value)
                ->where('operation_key', "planning-request:{$fresh->id}:human-review")
                ->lockForUpdate()
                ->first();
            if (! $reservation || (int) $reservation->quantity !== (int) $fresh->planning_units) {
                throw new ReviewerAssignmentException('REVIEW_ASSIGNMENT_HUMAN_RESERVATION_REQUIRED');
            }
            ($this->consume)($reservation);

            $profile = $candidate['profile'];
            $dueAt = $this->capacity->dueAt($fresh);
            if ($fresh->due_at === null && $dueAt !== null) {
                $fresh->forceFill(['due_at' => $dueAt])->save();
            }

            $cycle = max(1, (int) ReviewerAssignment::query()->where('request_id', $fresh->id)->max('cycle'));
            $assignment = ReviewerAssignment::query()->create([
                'request_id' => $fresh->id,
                'reviewer_id' => $profile->user_id,
                'cycle' => $cycle,
                'status' => ReviewAssignmentStatus::Assigned->value,
                'due_at' => $dueAt,
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

            $this->blocks->resolve($fresh, 'no_reviewer', 'human_review');

            $reviewer = User::query()->findOrFail($assignment->reviewer_id);
            $this->notifications->execute(
                OperationalNotificationType::ReviewAssigned,
                $reviewer,
                "review-assignment:{$assignment->id}:assigned",
                'review_assignment',
                (int) $assignment->id,
                [
                    'title' => 'Tienes una nueva revisión',
                    'body' => 'Se te asignó una planeación para revisión humana.',
                    'url' => '/review/assignments/' . $assignment->id,
                ],
            );

            return $assignment->fresh(['reviewer', 'profile']);
        }, attempts: 3);
    }

    private function assertRequestReady(PlanningRequest $request): void
    {
        if ($request->status !== PlanningRequestStatus::REVISION_HUMANA
            || $request->blocks()->where('code', 'human_review_attention')->where('stage', 'human_review')->whereNull('resolved_at')->exists()
            || ! $request->human_review_required_snapshot
            || (int) $request->planning_units <= 0) {
            throw new ReviewerAssignmentException('REVIEW_ASSIGNMENT_REQUEST_NOT_READY');
        }

        $currentVersionId = (int) ($request->document?->current_version_id ?? 0);
        if ($currentVersionId <= 0 || ! Approval::query()
            ->where('request_id', $request->id)
            ->where('version_id', $currentVersionId)
            ->where('kind', ApprovalKind::Ai->value)
            ->exists()) {
            throw new ReviewerAssignmentException('REVIEW_ASSIGNMENT_AI_APPROVAL_REQUIRED');
        }
    }
}
