<?php

namespace App\Policies;

use App\Models\Curriculum;
use App\Models\User;

class CurriculumPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->canReadCatalog($user);
    }

    public function view(User $user, Curriculum $curriculum): bool
    {
        return $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Curriculum $curriculum): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Curriculum $curriculum): bool
    {
        return $this->isAdmin($user);
    }

    public function selectVersion(User $user, Curriculum $curriculum): bool
    {
        return $this->isAdmin($user);
    }
}
