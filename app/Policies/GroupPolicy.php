<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\Group;
use App\Models\User;

class GroupPolicy
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

    public function view(User $user, Group $group): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }

        return $this->isActiveCustomer($user) && $group->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->isActiveCustomer($user);
    }

    public function update(User $user, Group $group): bool
    {
        return $this->isActiveCustomer($user) && $group->owner_id === $user->id && ! $group->isArchived();
    }

    public function delete(User $user, Group $group): bool
    {
        // Preferimos archivar en vez de eliminar; delete queda disponible solo para el dueño mientras no haya solicitudes (aplicable en 2C).
        return $this->isActiveCustomer($user) && $group->owner_id === $user->id;
    }

    public function archive(User $user, Group $group): bool
    {
        return $this->isActiveCustomer($user) && $group->owner_id === $user->id;
    }
}
