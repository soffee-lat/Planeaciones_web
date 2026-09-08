<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\PlanningRequest;
use App\Models\User;

class PlanningRequestPolicy
{
    private function isActiveCustomer(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Customer);
    }

    private function isAdmin(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Administrator);
    }

    public function viewAny(User $user): bool
    {
        return $this->isActiveCustomer($user) || $this->isAdmin($user);
    }

    public function view(User $user, PlanningRequest $request): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        return $this->isActiveCustomer($user) && $request->owner_id === $user->id;
    }

    public function create(User $user): bool
    {
        return $this->isActiveCustomer($user);
    }

    public function update(User $user, PlanningRequest $request): bool
    {
        // Solo el dueño y solo mientras esté en BORRADOR. Administrador
        // NO se salta esto: cambios de estado sobre solicitudes confirmadas
        // requieren acciones administrativas de fases posteriores.
        return $this->isActiveCustomer($user)
            && $request->owner_id === $user->id
            && $request->isDraft();
    }

    public function confirm(User $user, PlanningRequest $request): bool
    {
        return $this->isActiveCustomer($user)
            && $request->owner_id === $user->id
            && $request->isDraft();
    }

    public function authorizeProcessing(User $user, PlanningRequest $request): bool
    {
        return $this->isActiveCustomer($user) && $request->owner_id === $user->id;
    }

    public function delete(User $user, PlanningRequest $request): bool
    {
        // Solo borradores propios pueden borrarse; una vez confirmada, se
        // conserva por auditoría (cancelación llegará en fases posteriores).
        return $this->isActiveCustomer($user)
            && $request->owner_id === $user->id
            && $request->isDraft();
    }
}
