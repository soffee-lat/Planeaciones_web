<?php

namespace App\Policies;

use App\Models\SubscriptionPeriod;
use App\Models\User;

class SubscriptionPeriodPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, SubscriptionPeriod $period): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        return $user->id === $period->subscription?->customer_id && $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, SubscriptionPeriod $period): bool
    {
        return false; // snapshot inmutable; sólo transiciones automáticas.
    }

    public function delete(User $user, SubscriptionPeriod $period): bool
    {
        return false;
    }
}
