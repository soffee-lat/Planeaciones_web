<?php

namespace App\Actions\Schedules;

use App\Models\Group;
use App\Models\GroupSubject;
use Illuminate\Support\Str;

final class EnsureDefaultGroupSubjects
{
    /** @var list<string> */
    private const COLORS = [
        '#2563EB',
        '#7C3AED',
        '#059669',
        '#EA580C',
        '#DB2777',
        '#0891B2',
        '#4F46E5',
        '#65A30D',
    ];

    public function execute(Group $group): void
    {
        $group->loadMissing('curriculumVersion.formativeFields');

        $fields = $group->curriculumVersion?->formativeFields
            ?->sortBy('sort_order')
            ->values() ?? collect();

        foreach ($fields as $index => $field) {
            $slug = Str::slug((string) $field->name);
            $subject = GroupSubject::query()
                ->where('group_id', $group->id)
                ->where(function ($query) use ($field, $slug) {
                    $query->where('curriculum_field_code', $field->code)
                        ->orWhere('slug', $slug);
                })
                ->first();

            if ($subject) {
                $subject->fill([
                    'name' => (string) $field->name,
                    'slug' => $slug,
                    'origin' => 'official',
                    'curriculum_field_code' => (string) $field->code,
                    'is_active' => true,
                ])->save();

                continue;
            }

            GroupSubject::query()->create([
                'group_id' => $group->id,
                'name' => (string) $field->name,
                'slug' => $slug,
                'color' => self::COLORS[$index % count(self::COLORS)],
                'origin' => 'official',
                'curriculum_field_code' => (string) $field->code,
                'is_active' => true,
            ]);
        }
    }
}
