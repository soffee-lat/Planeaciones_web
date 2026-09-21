<?php

namespace App\Actions\Pedagogy;

use App\Models\GroupProfile;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;

/**
 * Persists changes on a `GroupProfile` and bumps `revision` iff any pedagogical
 * field actually changed. Revision drives snapshots on future planning
 * requests (Subfase 2C) and MUST NOT increment on no-op saves.
 */
class UpdateGroupProfile
{
    public function execute(User $actor, GroupProfile $profile, array $input): GroupProfile
    {
        Gate::forUser($actor)->authorize('update', $profile);

        $data = Validator::make($input, [
            'preferred_format_id' => ['nullable', 'integer'],
            'student_count' => ['nullable', 'integer', 'min:1', 'max:200'],
            'general_level' => ['nullable', 'string', 'max:64'],
            'characteristics' => ['nullable', 'string', 'max:8000'],
            'difficulties' => ['nullable', 'string', 'max:8000'],
            'educational_needs' => ['nullable', 'string', 'max:8000'],
            'session_minutes' => ['nullable', 'integer', 'min:15', 'max:480'],
            'available_materials' => ['nullable', 'string', 'max:8000'],
            'teaching_preferences' => ['nullable', 'string', 'max:8000'],
            'preferred_activities' => ['nullable', 'string', 'max:8000'],
            'restrictions' => ['nullable', 'string', 'max:8000'],
            'management_observations' => ['nullable', 'string', 'max:8000'],
            'required_structure' => ['nullable', 'string', 'max:8000'],
            'preferred_assessment_tools' => ['nullable', 'string', 'max:8000'],
            'additional_notes' => ['nullable', 'string', 'max:8000'],
        ])->validate();

        return DB::transaction(function () use ($profile, $data) {
            $fresh = GroupProfile::query()->lockForUpdate()->findOrFail($profile->id);

            foreach (GroupProfile::EXPORT_FIELDS as $field) {
                if (array_key_exists($field, $data) && $fresh->getAttribute($field) !== $data[$field]) {
                    $fresh->setAttribute($field, $data[$field]);
                }
            }

            $changed = false;
            foreach (GroupProfile::PEDAGOGICAL_FIELDS as $field) {
                if (! array_key_exists($field, $data)) {
                    continue;
                }
                $newValue = $data[$field];
                if ($fresh->getAttribute($field) !== $newValue) {
                    $fresh->setAttribute($field, $newValue);
                    $changed = true;
                }
            }

            if ($changed) {
                $fresh->revision = (int) $fresh->revision + 1;
            }
            $fresh->save();

            return $fresh->refresh();
        });
    }
}
