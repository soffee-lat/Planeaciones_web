<?php

namespace Database\Factories;

use App\Models\Group;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * NOTE: Group factory does NOT provide defaults for `owner_id`, `school_id`,
 * `curriculum_version_id` or `grade_id` because those must be assembled by
 * the caller so that:
 *   - school.owner_id === owner_id (composite FK enforced by PG);
 *   - grade.curriculum_version_id === curriculum_version_id (composite FK);
 *   - curriculum_version is the selectable published version (trigger guard).
 *
 * The `SchoolTest`/`GroupTest` helpers assemble a valid scenario using the
 * DEMO curriculum seeder or by publishing a `buildValidDraft()`.
 *
 * @extends Factory<Group>
 */
class GroupFactory extends Factory
{
    protected $model = Group::class;

    public function definition(): array
    {
        return [
            'name' => 'Grupo ' . fake()->unique()->bothify('??-###'),
            'school_year' => (string) now()->year . '-' . (now()->year + 1),
            'archived_at' => null,
        ];
    }

    public function archived(): static
    {
        return $this->state(fn () => ['archived_at' => now()]);
    }
}
