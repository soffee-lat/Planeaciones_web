<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\ReviewerProfile;
use App\Models\User;

class ReviewerProfilePolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, ReviewerProfile $profile): bool
    {
        return $this->isAdmin($user) || ($user->id === $profile->user_id && $this->isReviewer($user));
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, ReviewerProfile $profile): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, ReviewerProfile $profile): bool
    {
        return false;
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
