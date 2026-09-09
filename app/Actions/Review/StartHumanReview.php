<?php

namespace App\Actions\Review;

use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\ReviewChecklistVersionStatus;
use App\Enums\RoleCode;
use App\Exceptions\HumanReviewException;
use App\Models\HumanReview;
use App\Models\ReviewChecklistVersion;
use App\Models\ReviewerAssignment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class StartHumanReview
{
    public function execute(ReviewerAssignment $assignment, User $actor): HumanReview
    {
        $this->assertReviewer($actor, $assignment);

        return DB::transaction(function () use ($assignment, $actor): HumanReview {
            $locked = ReviewerAssignment::query()->whereKey($assignment->id)->lockForUpdate()->firstOrFail();
            $this->assertReviewer($actor, $locked);

            $request = $locked->request()->lockForUpdate()->firstOrFail();
            if ($request->status !== PlanningRequestStatus::REVISION_HUMANA) {
                throw new HumanReviewException('HUMAN_REVIEW_REQUEST_STATE_INVALID');
            }
            if (! in_array($locked->status, [ReviewAssignmentStatus::Assigned, ReviewAssignmentStatus::InProgress], true)) {
                throw new HumanReviewException('HUMAN_REVIEW_ASSIGNMENT_STATE_INVALID');
            }

            $document = $request->document()->with('currentVersion')->lockForUpdate()->first();
            if (! $document || ! $document->currentVersion) {
                throw new HumanReviewException('HUMAN_REVIEW_DOCUMENT_VERSION_REQUIRED');
            }

            $existing = HumanReview::query()->where('assignment_id', $locked->id)->lockForUpdate()->first();
            if ($existing) {
                if ((int) $existing->request_id !== (int) $request->id
                    || (int) $existing->reviewer_id !== (int) $locked->reviewer_id
                    || (int) $existing->version_id !== (int) $document->current_version_id) {
                    throw new HumanReviewException('HUMAN_REVIEW_IDEMPOTENCY_CONFLICT');
                }
                return $existing->fresh(['checklistVersion.items', 'responses']);
            }

            $checklist = ReviewChecklistVersion::query()
                ->where('key', 'standard')
                ->where('status', ReviewChecklistVersionStatus::Published->value)
                ->orderByDesc('version')
                ->lockForUpdate()
                ->first();
            if (! $checklist) {
                throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_PUBLISHED_REQUIRED');
            }

            if ($locked->status === ReviewAssignmentStatus::Assigned) {
                $locked->forceFill([
                    'status' => ReviewAssignmentStatus::InProgress->value,
                    'started_at' => now(),
                ])->save();
            }

            $review = HumanReview::query()->create([
                'request_id' => $request->id,
                'assignment_id' => $locked->id,
                'version_id' => $document->current_version_id,
                'checklist_version_id' => $checklist->id,
                'reviewer_id' => $locked->reviewer_id,
                'status' => HumanReviewStatus::InProgress->value,
                'started_at' => $locked->started_at ?? now(),
                'decided_at' => null,
                'general_comment' => null,
                'section_comments' => (object) [],
            ]);

            return $review->fresh(['checklistVersion.items', 'responses']);
        }, attempts: 3);
    }

    private function assertReviewer(User $actor, ReviewerAssignment $assignment): void
    {
        if ($actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasRole(RoleCode::Reviewer)
            || (int) $assignment->reviewer_id !== (int) $actor->id) {
            throw new HumanReviewException('HUMAN_REVIEW_REVIEWER_FORBIDDEN');
        }
    }
}
