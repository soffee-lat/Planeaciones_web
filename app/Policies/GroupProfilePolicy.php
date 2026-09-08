<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\GroupProfile;
use App\Models\User;

class GroupProfilePolicy
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

    private function ownsGroup(User $user, GroupProfile $profile): bool
    {
        $group = $profile->group ?? $profile->group()->first();

        return $group !== null && $group->owner_id === $user->id;
    }

    public function viewAny(User $user): bool
    {
        return $this->isActiveCustomer($user) || $this->isAdmin($user);
    }

    public function view(User $user, GroupProfile $profile): bool
    {
        return $this->isAdmin($user) || ($this->isActiveCustomer($user) && $this->ownsGroup($user, $profile));
    }

    public function create(User $user): bool
    {
        return $this->isActiveCustomer($user);
    }

    public function update(User $user, GroupProfile $profile): bool
    {
        return $this->isActiveCustomer($user) && $this->ownsGroup($user, $profile);
    }

    public function delete(User $user, GroupProfile $profile): bool
    {
        return false;
    }
}
