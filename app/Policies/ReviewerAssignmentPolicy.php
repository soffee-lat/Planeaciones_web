<?php

namespace App\Policies;

use App\Enums\ReviewAssignmentStatus;
use App\Enums\RoleCode;
use App\Models\ReviewerAssignment;
use App\Models\User;

class ReviewerAssignmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user) || $this->isReviewer($user);
    }

    public function view(User $user, ReviewerAssignment $assignment): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->isReviewer($user)
            && $assignment->reviewer_id === $user->id
            && in_array($assignment->status, [ReviewAssignmentStatus::Assigned, ReviewAssignmentStatus::InProgress], true);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, ReviewerAssignment $assignment): bool
    {
        return false;
    }

    public function delete(User $user, ReviewerAssignment $assignment): bool
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
