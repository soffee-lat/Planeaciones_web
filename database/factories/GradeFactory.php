<?php

namespace Database\Factories;

use App\Models\EducationalPhase;
use App\Models\Grade;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Grade> */
class GradeFactory extends Factory
{
    protected $model = Grade::class;

    public function definition(): array
    {
        $phase = EducationalPhase::factory()->create();

        return [
            'curriculum_version_id' => $phase->curriculum_version_id,
            'educational_phase_id' => $phase->id,
            'code' => 'GR-' . strtoupper($this->faker->unique()->bothify('??##')),
            'name' => 'Grado demo ' . $this->faker->numberBetween(1, 12),
            'ordinal' => $this->faker->numberBetween(1, 12),
            'sort_order' => 0,
        ];
    }
}
