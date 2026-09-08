<?php

namespace App\Policies;

use App\Models\PlanVersion;
use App\Models\User;

class PlanVersionPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->canReadCatalog($user);
    }

    public function view(User $user, PlanVersion $version): bool
    {
        return $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, PlanVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function delete(User $user, PlanVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function publish(User $user, PlanVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }
}
