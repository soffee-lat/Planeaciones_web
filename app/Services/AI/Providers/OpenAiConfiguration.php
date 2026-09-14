<?php

namespace App\Services\AI\Providers;

use App\Exceptions\AiPipelineException;

final class OpenAiConfiguration
{
    /**
     * @return array{base_url:string,api_key:string,model:string,timeout_seconds:int,connect_timeout_seconds:int,max_output_tokens:int,reasoning_effort:string,strict_schema:bool}
     */
    public function values(): array
    {
        $baseUrl = rtrim(trim((string) config('ai.api.openai.base_url', 'https://api.openai.com/v1')), '/');
        $apiKey = trim((string) config('ai.api.openai.api_key', ''));
        $model = trim((string) config('ai.api.openai.model', 'gpt-5.6-luna'));
        $timeout = (int) config('ai.api.openai.timeout_seconds', 90);
        $connectTimeout = (int) config('ai.api.openai.connect_timeout_seconds', 10);
        $maxOutputTokens = (int) config('ai.api.openai.max_output_tokens', 12000);
        $reasoningEffort = trim((string) config('ai.api.openai.reasoning_effort', 'low'));
        $strictSchema = (bool) config('ai.api.openai.strict_schema', false);

        if ($apiKey === '') {
            throw new AiPipelineException('AI_OPENAI_API_KEY_MISSING');
        }
        if ($model === '' || mb_strlen($model) > 128) {
            throw new AiPipelineException('AI_OPENAI_MODEL_INVALID');
        }
        if (filter_var($baseUrl, FILTER_VALIDATE_URL) === false
            || strtolower((string) parse_url($baseUrl, PHP_URL_SCHEME)) !== 'https') {
            throw new AiPipelineException('AI_OPENAI_BASE_URL_INVALID');
        }
        if ($timeout < 1 || $timeout > 300) {
            throw new AiPipelineException('AI_OPENAI_TIMEOUT_INVALID');
        }
        if ($connectTimeout < 1 || $connectTimeout > $timeout) {
            throw new AiPipelineException('AI_OPENAI_CONNECT_TIMEOUT_INVALID');
        }
        if ($maxOutputTokens < 256 || $maxOutputTokens > 128000) {
            throw new AiPipelineException('AI_OPENAI_MAX_OUTPUT_TOKENS_INVALID');
        }
        if (! in_array($reasoningEffort, ['none', 'low', 'medium', 'high', 'xhigh', 'max'], true)) {
            throw new AiPipelineException('AI_OPENAI_REASONING_EFFORT_INVALID');
        }

        return [
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'model' => $model,
            'timeout_seconds' => $timeout,
            'connect_timeout_seconds' => $connectTimeout,
            'max_output_tokens' => $maxOutputTokens,
            'reasoning_effort' => $reasoningEffort,
            // false por defecto: nuestros contratos internos permiten algunos
            // campos opcionales y siguen siendo validados localmente. Activar
            // strict sólo cuando el schema efectivo cumpla el subconjunto del
            // proveedor sin alterar el contrato soberano del formato.
            'strict_schema' => $strictSchema,
        ];
    }
}
