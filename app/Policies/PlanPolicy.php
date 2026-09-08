<?php

namespace App\Policies;

use App\Models\Plan;
use App\Models\User;

class PlanPolicy
{
    use AuthorizesCurriculumTree;

    public function viewAny(User $user): bool
    {
        return $this->canReadCatalog($user);
    }

    public function view(User $user, Plan $plan): bool
    {
        return $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Plan $plan): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Plan $plan): bool
    {
        return $this->isAdmin($user) && $plan->versions()->whereNotNull('published_at')->doesntExist();
    }
}
