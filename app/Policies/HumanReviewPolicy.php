<?php

namespace App\Policies;

use App\Enums\HumanReviewStatus;
use App\Enums\RoleCode;
use App\Models\HumanReview;
use App\Models\User;

class HumanReviewPolicy
{
    public function view(User $user, HumanReview $review): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->isReviewer($user)
            && (int) $review->reviewer_id === (int) $user->id
            && $review->status === HumanReviewStatus::InProgress;
    }

    public function update(User $user, HumanReview $review): bool
    {
        return $this->isReviewer($user)
            && (int) $review->reviewer_id === (int) $user->id
            && $review->status === HumanReviewStatus::InProgress;
    }

    public function approve(User $user, HumanReview $review): bool
    {
        return $this->update($user, $review);
    }

    private function isAdmin(User $user): bool
    {
        return $user->status === 'active' && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Administrator);
    }

    private function isReviewer(User $user): bool
    {
        return $user->status === 'active' && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Reviewer);
    }
}
