<?php

namespace App\Services\AI;

use App\Enums\PromptCategory;
use App\Exceptions\AiPipelineException;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;

final class AuditPromptSelector
{
    public function activeForUpdate(): PromptVersion
    {
        $templateKey = trim((string) config('ai.prompts.audit_key', 'planning.audit'));
        if ($templateKey === '') {
            throw new AiPipelineException('AI_AUDIT_PROMPT_KEY_INVALID');
        }

        /** @var PromptTemplate|null $template */
        $template = PromptTemplate::query()
            ->where('key', $templateKey)
            ->where('category', PromptCategory::Audit->value)
            ->lockForUpdate()
            ->first();

        if (! $template || $template->active_version_id === null) {
            throw new AiPipelineException('AI_AUDIT_ACTIVE_PROMPT_MISSING');
        }

        /** @var PromptVersion|null $prompt */
        $prompt = PromptVersion::query()
            ->whereKey($template->active_version_id)
            ->where('template_id', $template->id)
            ->lockForUpdate()
            ->first();

        if (! $prompt || ! $prompt->isPublished() || ! $prompt->checksum) {
            throw new AiPipelineException('AI_AUDIT_ACTIVE_PROMPT_INVALID');
        }

        return $prompt;
    }
}
