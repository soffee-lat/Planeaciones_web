<?php

namespace App\Policies;

use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Order $order): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        return $user->id === $order->customer_id && $this->canReadCatalog($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Order $order): bool
    {
        // No hay edición libre: sólo Actions de dominio.
        return false;
    }

    public function delete(User $user, Order $order): bool
    {
        return false;
    }
}
