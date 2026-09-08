<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\School;
use App\Models\User;

class SchoolPolicy
{
    private function isActiveCustomer(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Customer);
    }

    private function isAdmin(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Administrator);
    }

    public function viewAny(User $user): bool
    {
        return $this->isActiveCustomer($user) || $this->isAdmin($user);
    }

    public function view(User $user, School $school): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->isActiveCustomer($user) && $school->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->isActiveCustomer($user);
    }

    public function update(User $user, School $school): bool
    {
        return $this->isActiveCustomer($user) && $school->owner_id === $user->id;
    }

    public function delete(User $user, School $school): bool
    {
        return $this->isActiveCustomer($user) && $school->owner_id === $user->id;
    }
}
