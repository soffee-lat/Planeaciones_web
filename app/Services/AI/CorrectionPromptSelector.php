<?php

namespace App\Services\AI;

use App\Enums\PromptCategory;
use App\Exceptions\AiPipelineException;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;

final class CorrectionPromptSelector
{
    public function activeForUpdate(): PromptVersion
    {
        $templateKey = trim((string) config('ai.prompts.correction_key', 'planning.correction'));
        if ($templateKey === '') {
            throw new AiPipelineException('AI_CORRECTION_PROMPT_KEY_INVALID');
        }

        $template = PromptTemplate::query()
            ->where('key', $templateKey)
            ->where('category', PromptCategory::Correction->value)
            ->lockForUpdate()
            ->first();

        if (! $template || $template->active_version_id === null) {
            throw new AiPipelineException('AI_CORRECTION_ACTIVE_PROMPT_MISSING');
        }

        $prompt = PromptVersion::query()
            ->whereKey($template->active_version_id)
            ->where('template_id', $template->id)
            ->lockForUpdate()
            ->first();

        if (! $prompt || ! $prompt->isPublished() || ! $prompt->checksum) {
            throw new AiPipelineException('AI_CORRECTION_ACTIVE_PROMPT_INVALID');
        }

        return $prompt;
    }
}
