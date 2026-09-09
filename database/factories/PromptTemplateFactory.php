<?php

namespace Database\Factories;

use App\Enums\PromptCategory;
use App\Models\PromptTemplate;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PromptTemplate> */
class PromptTemplateFactory extends Factory
{
    protected $model = PromptTemplate::class;

    public function definition(): array
    {
        return [
            'key' => 'demo.' . fake()->unique()->slug(2),
            'category' => PromptCategory::Generation->value,
            'name' => 'Prompt DEMO ' . fake()->unique()->numerify('###'),
            'active_version_id' => null,
        ];
    }
}
