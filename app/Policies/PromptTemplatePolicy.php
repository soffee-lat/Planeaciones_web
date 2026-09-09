<?php

namespace App\Policies;

use App\Models\PromptTemplate;
use App\Models\User;

class PromptTemplatePolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, PromptTemplate $template): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, PromptTemplate $template): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, PromptTemplate $template): bool
    {
        return $this->isAdmin($user) && ! $template->versions()->exists();
    }
}
