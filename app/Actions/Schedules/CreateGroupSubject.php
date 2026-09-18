<?php

namespace App\Actions\Schedules;

use App\Models\Group;
use App\Models\GroupSubject;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

final class CreateGroupSubject
{
    /** @param array{name:mixed,color:mixed} $input */
    public function execute(User $actor, Group $group, array $input): GroupSubject
    {
        Gate::forUser($actor)->authorize('update', $group);

        $data = Validator::make($input, [
            'name' => [
                'required',
                'string',
                'max:120',
                Rule::unique('group_subjects', 'name')->where('group_id', $group->id),
            ],
            'color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ])->validate();

        return GroupSubject::query()->create([
            'group_id' => $group->id,
            'name' => trim($data['name']),
            'slug' => Str::slug(trim($data['name'])),
            'color' => strtoupper($data['color']),
            'origin' => 'custom',
            'curriculum_field_code' => null,
            'is_active' => true,
        ]);
    }
}
