<?php

namespace Database\Factories;

use App\Models\CurricularContent;
use App\Models\Grade;
use App\Models\Pda;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Pda> */
class PdaFactory extends Factory
{
    protected $model = Pda::class;

    public function definition(): array
    {
        $content = CurricularContent::factory()->create();
        $grade = Grade::factory()->create([
            'curriculum_version_id' => $content->curriculum_version_id,
            'educational_phase_id' => $content->educational_phase_id,
        ]);

        return [
            'curriculum_version_id' => $content->curriculum_version_id,
            'curricular_content_id' => $content->id,
            'grade_id' => $grade->id,
            'code' => 'PDA-' . strtoupper($this->faker->unique()->bothify('??##')),
            'full_text' => 'PDA de ejemplo. Datos ficticios, sin validez curricular.',
            'source_locator' => null,
            'sort_order' => 0,
        ];
    }
}
