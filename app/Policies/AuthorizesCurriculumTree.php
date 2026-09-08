<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

trait AuthorizesCurriculumTree
{
    protected function isAdmin(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Administrator);
    }

    protected function canReadCatalog(User $user): bool
    {
        if ($user->status !== 'active' || ! $user->hasVerifiedEmail()) {
            return false;
        }

        return $user->hasRole(RoleCode::Administrator)
            || $user->hasRole(RoleCode::Reviewer)
            || $user->hasRole(RoleCode::Customer);
    }
}
