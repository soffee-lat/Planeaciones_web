<?php

namespace App\Policies;

use App\Models\UsageReservation;
use App\Models\User;

class UsageReservationPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, UsageReservation $reservation): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        return $user->id === $reservation->subscriptionPeriod?->subscription?->customer_id
            && $this->canReadCatalog($user);
    }

    // El ledger no admite creación/edición manual desde Filament.
    public function create(User $user): bool
    {
        return false;
    }

    public function update(User $user, UsageReservation $reservation): bool
    {
        return false;
    }

    public function delete(User $user, UsageReservation $reservation): bool
    {
        return false;
    }
}
