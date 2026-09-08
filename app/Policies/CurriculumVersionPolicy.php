<?php

namespace App\Policies;

use App\Models\CurriculumVersion;
use App\Models\User;

class CurriculumVersionPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->canReadCatalog($user);
    }

    public function view(User $user, CurriculumVersion $version): bool
    {
        return $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, CurriculumVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function delete(User $user, CurriculumVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function publish(User $user, CurriculumVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function editTree(User $user, CurriculumVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }
}
