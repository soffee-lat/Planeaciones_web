<?php

namespace Database\Factories;

use App\Models\CurriculumVersion;
use App\Models\FormativeField;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<FormativeField> */
class FormativeFieldFactory extends Factory
{
    protected $model = FormativeField::class;

    public function definition(): array
    {
        return [
            'curriculum_version_id' => CurriculumVersion::factory(),
            'code' => 'FF-' . strtoupper($this->faker->unique()->bothify('??##')),
            'name' => 'Campo demo ' . $this->faker->words(1, true),
            'description' => 'Campo ficticio',
            'sort_order' => 0,
        ];
    }
}
