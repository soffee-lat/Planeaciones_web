<?php

namespace App\Actions\Review;

use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Exceptions\HumanReviewException;
use App\Models\HumanReview;
use App\Models\ReviewChecklistResponse;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class SaveHumanReview
{
    /**
     * @param array<string,array{passed:bool,comment?:string|null}> $responses
     * @param array<string,string|null> $sectionComments
     */
    public function execute(
        HumanReview $review,
        User $actor,
        array $responses,
        array $sectionComments = [],
        ?string $generalComment = null,
    ): HumanReview {
        return DB::transaction(function () use ($review, $actor, $responses, $sectionComments, $generalComment): HumanReview {
            $locked = HumanReview::query()->whereKey($review->id)->lockForUpdate()->firstOrFail();
            $assignment = $locked->assignment()->lockForUpdate()->firstOrFail();
            $request = $locked->request()->lockForUpdate()->firstOrFail();

            $this->assertEditable($locked, $assignment, $request, $actor);

            $document = $request->document()->lockForUpdate()->first();
            if (! $document || (int) $document->current_version_id !== (int) $locked->version_id) {
                throw new HumanReviewException('HUMAN_REVIEW_VERSION_STALE');
            }

            $items = $locked->checklistVersion()->with('items')->firstOrFail()->items->keyBy('key');
            foreach ($responses as $key => $payload) {
                if (! is_string($key) || ! $items->has($key) || ! is_array($payload) || ! array_key_exists('passed', $payload) || ! is_bool($payload['passed'])) {
                    throw new HumanReviewException('HUMAN_REVIEW_RESPONSE_INVALID');
                }
                $comment = $this->normalizeText($payload['comment'] ?? null, 4000, 'HUMAN_REVIEW_ITEM_COMMENT_TOO_LONG');
                ReviewChecklistResponse::query()->updateOrCreate(
                    ['review_id' => $locked->id, 'checklist_item_id' => $items[$key]->id],
                    ['passed' => $payload['passed'], 'comment' => $comment],
                );
            }

            $normalizedSections = [];
            foreach ($sectionComments as $key => $comment) {
                if (! in_array($key, HumanReview::SECTION_KEYS, true)) {
                    throw new HumanReviewException('HUMAN_REVIEW_SECTION_KEY_INVALID');
                }
                $text = $this->normalizeText($comment, 4000, 'HUMAN_REVIEW_SECTION_COMMENT_TOO_LONG');
                if ($text !== null) {
                    $normalizedSections[$key] = $text;
                }
            }
            ksort($normalizedSections, SORT_STRING);

            $locked->forceFill([
                'general_comment' => $this->normalizeText($generalComment, 8000, 'HUMAN_REVIEW_GENERAL_COMMENT_TOO_LONG'),
                'section_comments' => $normalizedSections === [] ? (object) [] : $normalizedSections,
            ])->save();

            return $locked->fresh(['checklistVersion.items', 'responses.item']);
        }, attempts: 3);
    }

    private function assertEditable(HumanReview $review, $assignment, $request, User $actor): void
    {
        if ($actor->status !== 'active'
            || ! $actor->hasVerifiedEmail()
            || ! $actor->hasRole(RoleCode::Reviewer)
            || (int) $review->reviewer_id !== (int) $actor->id
            || (int) $assignment->reviewer_id !== (int) $actor->id) {
            throw new HumanReviewException('HUMAN_REVIEW_REVIEWER_FORBIDDEN');
        }
        if ($review->status !== HumanReviewStatus::InProgress
            || $assignment->status !== ReviewAssignmentStatus::InProgress
            || $request->status !== PlanningRequestStatus::REVISION_HUMANA) {
            throw new HumanReviewException('HUMAN_REVIEW_NOT_EDITABLE');
        }
    }

    private function normalizeText(mixed $value, int $max, string $errorCode): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value)) {
            throw new HumanReviewException($errorCode);
        }
        $value = trim($value);
        if ($value === '') {
            return null;
        }
        if (mb_strlen($value) > $max) {
            throw new HumanReviewException($errorCode);
        }
        return $value;
    }
}
