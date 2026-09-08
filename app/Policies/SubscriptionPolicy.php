<?php

namespace App\Policies;

use App\Models\Subscription;
use App\Models\User;

class SubscriptionPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Subscription $subscription): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        return $user->id === $subscription->customer_id && $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Subscription $subscription): bool
    {
        return $this->isAdmin($user);
    }

    public function delete(User $user, Subscription $subscription): bool
    {
        return false;
    }
}
