<?php

namespace App\Services\AI;

use App\Enums\PromptCategory;
use App\Exceptions\AiPipelineException;
use App\Models\PromptVersion;
use App\Support\AI\CanonicalJson;

final class CorrectionPromptPolicy
{
    /** @return list<string> */
    public function supportedVariables(): array
    {
        return [
            'request_id', 'input_revision', 'canonical_plan', 'source_version_id',
            'source_content_hash', 'audit_report', 'section_keys', 'correction_round',
            'input_manifest', 'output_schema', 'output_schema_version', 'correlation_id',
        ];
    }

    public function assertReady(PromptVersion $prompt): void
    {
        $prompt->loadMissing('template');
        $template = $prompt->template;
        $expectedKey = trim((string) config('ai.prompts.correction_key', 'planning.correction'));
        if (! $template || $template->category !== PromptCategory::Correction || $template->key !== $expectedKey) {
            throw new AiPipelineException('AI_CORRECTION_PROMPT_TEMPLATE_INVALID');
        }
        if (! $prompt->isPublished() || ! $prompt->checksum) {
            throw new AiPipelineException('AI_CORRECTION_ACTIVE_PROMPT_INVALID');
        }
        if ($prompt->schema_version !== CorrectionResultValidator::CONTRACT_VERSION) {
            throw new AiPipelineException('AI_CORRECTION_SCHEMA_VERSION_UNSUPPORTED', (string) $prompt->schema_version);
        }

        $allowed = array_values(array_unique(array_map('strval', $prompt->allowed_variables ?? [])));
        foreach ($allowed as $name) {
            if (! in_array($name, $this->supportedVariables(), true)) {
                throw new AiPipelineException('AI_CORRECTION_PROMPT_VARIABLE_UNSUPPORTED', $name);
            }
        }
        foreach (['canonical_plan', 'audit_report', 'section_keys', 'output_schema'] as $required) {
            if (! in_array($required, $allowed, true)
                || preg_match('/{{\s*' . preg_quote($required, '/') . '\s*}}/', $prompt->body) !== 1) {
                throw new AiPipelineException('AI_CORRECTION_PROMPT_REQUIRED_VARIABLE_MISSING', $required);
            }
        }

        $expectedSchema = json_decode(
            file_get_contents(resource_path('schemas/ai/correction_result_v1.schema.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (! is_array($prompt->output_schema)
            || CanonicalJson::hash($prompt->output_schema) !== CanonicalJson::hash($expectedSchema)) {
            throw new AiPipelineException('AI_CORRECTION_PROMPT_SCHEMA_MISMATCH');
        }
    }
}
