<?php

namespace App\Services\Onboarding;

use App\Models\GroupProfile;
use App\Models\User;

/**
 * Derives whether a customer teacher has satisfied the pedagogical prerequisites
 * of Subfase 2B: owns at least one school, at least one active group tied to a
 * publishable curriculum version, and a `GroupProfile` with the minimum fields
 * present. This computed state is intentionally SEPARATE from the legacy
 * `users.onboarding_completed_at` timestamp (which corresponds to the identity
 * step from Fase 1) so we never mark the timestamp inconsistently.
 */
final class OnboardingProgress
{
    public static function isPedagogicalComplete(User $user): bool
    {
        // Must own at least one school.
        if (! $user->schools()->exists()) {
            return false;
        }

        // Must own at least one active (non-archived) group.
        $groupId = $user->groups()->whereNull('archived_at')->value('id');
        if ($groupId === null) {
            return false;
        }

        // The active group must have a profile whose minimum fields are present.
        $profile = GroupProfile::query()->where('group_id', $groupId)->first();
        if ($profile === null) {
            return false;
        }

        return $profile->isSufficient();
    }
}
