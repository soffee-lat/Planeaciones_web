<?php

namespace App\Policies;

use App\Models\PromptVersion;
use App\Models\User;

class PromptVersionPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, PromptVersion $version): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, PromptVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function delete(User $user, PromptVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }

    public function publish(User $user, PromptVersion $version): bool
    {
        return $this->isAdmin($user) && $version->isDraft();
    }
}
