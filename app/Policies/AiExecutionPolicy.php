<?php

namespace App\Policies;

use App\Models\AiExecution;
use App\Models\User;

class AiExecutionPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, AiExecution $execution): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, AiExecution $execution): bool
    {
        return false;
    }

    public function delete(User $user, AiExecution $execution): bool
    {
        return false;
    }
}
