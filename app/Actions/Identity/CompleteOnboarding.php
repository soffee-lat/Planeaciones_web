<?php
namespace App\Actions\Identity;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
class CompleteOnboarding {
    public function execute(User $actor, User $target, array $input): void {
        Gate::forUser($actor)->authorize('completeOnboarding', $target);
        $data = Validator::make($input, ['name' => ['required', 'string', 'max:255']])->validate();
        $target->forceFill(['name' => $data['name'], 'onboarding_completed_at' => $target->onboarding_completed_at ?? now()])->save();
    }
}

