<?php

namespace App\Policies;

use App\Models\PaymentEvent;
use App\Models\User;

class PaymentEventPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, PaymentEvent $event): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return false; // Se ingestan por gateway/webhook, no por Filament.
    }

    public function update(User $user, PaymentEvent $event): bool
    {
        return false;
    }

    public function delete(User $user, PaymentEvent $event): bool
    {
        return false;
    }
}
