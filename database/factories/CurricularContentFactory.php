<?php

namespace Database\Factories;

use App\Models\CurricularContent;
use App\Models\EducationalPhase;
use App\Models\FormativeField;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<CurricularContent> */
class CurricularContentFactory extends Factory
{
    protected $model = CurricularContent::class;

    public function definition(): array
    {
        $phase = EducationalPhase::factory()->create();
        $field = FormativeField::factory()->create(['curriculum_version_id' => $phase->curriculum_version_id]);

        return [
            'curriculum_version_id' => $phase->curriculum_version_id,
            'educational_phase_id' => $phase->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-' . strtoupper($this->faker->unique()->bothify('??##')),
            'title' => 'Contenido demo ' . $this->faker->words(2, true),
            'full_text' => 'Contenido de ejemplo. Datos ficticios, sin validez curricular.',
            'source_locator' => null,
            'sort_order' => 0,
        ];
    }
}
