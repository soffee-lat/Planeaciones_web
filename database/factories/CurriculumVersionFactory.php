<?php

namespace Database\Factories;

use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CurriculumVersion> */
class CurriculumVersionFactory extends Factory
{
    protected $model = CurriculumVersion::class;

    public function definition(): array
    {
        return [
            'curriculum_id' => Curriculum::factory(),
            'number' => $this->faker->unique()->numberBetween(1, 999999),
            'label' => 'DEMO-' . $this->faker->numerify('##'),
            'source_reference' => 'ficticio',
            'effective_from' => null,
            'effective_until' => null,
            'published_at' => null,
            'published_by' => null,
            'checksum' => null,
        ];
    }
}
