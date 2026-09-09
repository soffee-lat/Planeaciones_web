<?php

namespace App\Actions\Review;

use App\Enums\ReviewChecklistVersionStatus;
use App\Enums\RoleCode;
use App\Exceptions\HumanReviewException;
use App\Models\ReviewChecklistVersion;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final class PublishReviewChecklistVersion
{
    public function execute(User $actor, ReviewChecklistVersion $version): ReviewChecklistVersion
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_PUBLISH_FORBIDDEN');
        }

        return DB::transaction(function () use ($version): ReviewChecklistVersion {
            $locked = ReviewChecklistVersion::query()->whereKey($version->id)->lockForUpdate()->firstOrFail();
            if ($locked->status === ReviewChecklistVersionStatus::Published) {
                return $locked->fresh('items');
            }
            if ($locked->status !== ReviewChecklistVersionStatus::Draft) {
                throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_STATE_INVALID');
            }

            $items = $locked->items()->orderBy('sort_order')->get();
            if ($items->isEmpty() || ! $items->contains(fn ($item): bool => (bool) $item->required)) {
                throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_ITEMS_REQUIRED');
            }
            if ($items->pluck('key')->filter()->count() !== $items->count()) {
                throw new HumanReviewException('HUMAN_REVIEW_CHECKLIST_ITEM_KEY_REQUIRED');
            }

            $locked->forceFill([
                'status' => ReviewChecklistVersionStatus::Published->value,
                'published_at' => now(),
            ])->save();

            return $locked->fresh('items');
        }, attempts: 3);
    }
}
