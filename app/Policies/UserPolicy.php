<?php
namespace App\Policies;
use App\Enums\RoleCode;
use App\Models\User;
class UserPolicy
{
    public function view(User $actor, User $target): bool {
        return $actor->status === 'active' && $actor->hasVerifiedEmail()
            && ($actor->is($target) || $actor->hasRole(RoleCode::Administrator));
    }
    public function update(User $actor, User $target): bool {
        return $actor->status === 'active' && $actor->hasVerifiedEmail() && $actor->is($target);
    }
    public function completeOnboarding(User $actor, User $target): bool {
        return $this->update($actor, $target) && $actor->hasRole(RoleCode::Customer);
    }
}

