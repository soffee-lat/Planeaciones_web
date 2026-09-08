<?php

namespace Database\Factories;

use App\Models\Curriculum;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Curriculum> */
class CurriculumFactory extends Factory
{
    protected $model = Curriculum::class;

    public function definition(): array
    {
        return [
            'code' => 'DEMO-' . $this->faker->unique()->numerify('####'),
            'name' => 'Currículo demo ' . $this->faker->words(2, true),
            'country_code' => 'DEMO',
            'educational_level' => 'demo',
            'description' => 'Datos ficticios, sin validez curricular.',
            'selectable_version_id' => null,
        ];
    }
}
