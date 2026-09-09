<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\ReviewChecklistVersion;
use App\Models\User;

class ReviewChecklistVersionPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, ReviewChecklistVersion $version): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, ReviewChecklistVersion $version): bool
    {
        return $this->isAdmin($user) && $version->status->value === 'draft';
    }

    public function publish(User $user, ReviewChecklistVersion $version): bool
    {
        return $this->update($user, $version);
    }

    public function delete(User $user, ReviewChecklistVersion $version): bool
    {
        return false;
    }

    private function isAdmin(User $user): bool
    {
        return $user->status === 'active' && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Administrator);
    }
}
