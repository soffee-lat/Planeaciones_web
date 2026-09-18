<?php

namespace App\Actions\Schedules;

use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

final class UpdateGroupSubjectColor
{
    public function execute(User $actor, Group $group, GroupSubject $subject, string $color): GroupSubject
    {
        Gate::forUser($actor)->authorize('update', $group);

        if ((int) $subject->group_id !== (int) $group->id) {
            throw ValidationException::withMessages([
                'subject' => 'La materia no pertenece a este grupo.',
            ]);
        }

        $data = Validator::make(
            ['color' => $color],
            ['color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/']],
        )->validate();

        $subject->update(['color' => strtoupper($data['color'])]);

        return $subject->fresh();
    }
}
