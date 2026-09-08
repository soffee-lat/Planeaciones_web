<?php

namespace App\Policies;

use App\Enums\RoleCode;
use App\Models\User;

/**
 * Autorización compartida por policies que sólo distinguen dos capas:
 *   - lectura: cualquier rol activo con email verificado (canReadCatalog);
 *   - escritura: sólo administrador activo y verificado (isAdmin).
 *
 * No contiene lógica curricular: el nombre histórico
 * `AuthorizesCurriculumTree` se renombró a este nombre neutral para
 * poder reutilizarlo en el módulo comercial (Plan/PlanVersion).
 */
trait AuthorizesActiveRoleUser
{
    protected function isAdmin(User $user): bool
    {
        return $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Administrator);
    }

    protected function canReadCatalog(User $user): bool
    {
        if ($user->status !== 'active' || ! $user->hasVerifiedEmail()) {
            return false;
        }

        return $user->hasRole(RoleCode::Administrator)
            || $user->hasRole(RoleCode::Reviewer)
            || $user->hasRole(RoleCode::Customer);
    }
}
