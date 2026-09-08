<?php

namespace App\Policies;

use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    use AuthorizesActiveRoleUser;

    public function viewAny(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->isAdmin($user);
    }

    public function create(User $user): bool
    {
        return $this->isAdmin($user);
    }

    public function update(User $user, Refund $refund): bool
    {
        return false;
    }

    public function delete(User $user, Refund $refund): bool
    {
        return false;
    }
}
