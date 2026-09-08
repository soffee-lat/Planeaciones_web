<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\EducationalPhase;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<EducationalPhase> */
class EducationalPhaseFactory extends Factory
{
    protected $model = EducationalPhase::class;

    public function definition(): array
    {
        return [
            'curriculum_version_id' => CurriculumVersion::factory(),
            'code' => 'PH-' . strtoupper($this->faker->unique()->bothify('??##')),
            'name' => 'Fase demo ' . $this->faker->words(1, true),
            'description' => 'Fase ficticia',
            'sort_order' => 0,
        ];
    }
}
