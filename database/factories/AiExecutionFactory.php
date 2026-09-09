<?php

namespace Database\Factories;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Models\AiExecution;
use App\Models\PromptVersion;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<AiExecution> */
class AiExecutionFactory extends Factory
{
    protected $model = AiExecution::class;

    public function definition(): array
    {
        return [
            'request_id' => null,
            'format_version_id' => 1,
            'stage' => AiExecutionStage::Generation->value,
            'mode' => AiExecutionMode::Manual->value,
            'provider' => null,
            'model' => null,
            'prompt_version_id' => PromptVersion::factory()->state(fn () => ['published_at' => now(), 'checksum' => str_repeat('a', 64)]),
            'input_revision' => null,
            'input_manifest' => ['schema_version' => 1],
            'rendered_prompt_hash' => null,
            'private_payload_file_id' => null,
            'operation_key' => 'demo:' . fake()->uuid(),
            'status' => AiExecutionStatus::Pending->value,
            'started_at' => null,
            'finished_at' => null,
            'duration_ms' => null,
            'error_code' => null,
            'sanitized_error' => null,
            'estimated_cost' => null,
            'actual_cost' => null,
            'cost_currency' => null,
            'resulting_version_id' => null,
            'audit_report' => null,
        ];
    }
}
