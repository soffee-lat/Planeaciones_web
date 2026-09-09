<?php

namespace Database\Factories;

use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromptVersion> */
class PromptVersionFactory extends Factory
{
    protected $model = PromptVersion::class;

    public function definition(): array
    {
        $schema = json_decode(file_get_contents(resource_path('schemas/ai/generated_plan_draft_v1.schema.json')), true, 512, JSON_THROW_ON_ERROR);

        return [
            'template_id' => PromptTemplate::factory(),
            'number' => fake()->unique()->numberBetween(1, 999999),
            'body' => 'Genera una planeación usando exclusivamente {{input_snapshot}} y responde según {{output_schema}}.',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
            'output_schema' => $schema,
            'schema_version' => 'generated_plan_draft_v1',
            'published_at' => null,
            'created_by' => null,
            'checksum' => null,
        ];
    }
}
