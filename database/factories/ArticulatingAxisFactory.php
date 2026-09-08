<?php

namespace Database\Factories;

use App\Models\ArticulatingAxis;
use App\Models\CurriculumVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<ArticulatingAxis> */
class ArticulatingAxisFactory extends Factory
{
    protected $model = ArticulatingAxis::class;

    public function definition(): array
    {
        return [
            'curriculum_version_id' => CurriculumVersion::factory(),
            'code' => 'AX-' . strtoupper($this->faker->unique()->bothify('??##')),
            'name' => 'Eje demo ' . $this->faker->words(1, true),
            'description' => 'Eje ficticio',
            'sort_order' => 0,
        ];
    }
}
