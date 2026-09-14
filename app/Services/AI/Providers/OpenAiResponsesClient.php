<?php

namespace App\Services\AI\Providers;

use App\Data\AI\StructuredProviderResponse;
use App\Exceptions\AiPipelineException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use JsonException;

final class OpenAiResponsesClient
{
    public function __construct(private OpenAiConfiguration $configuration) {}

    /**
     * @param array<string,mixed> $outputSchema
     */
    public function respond(
        string $renderedPrompt,
        array $outputSchema,
        string $schemaName,
        ?string $operationKey = null,
    ): StructuredProviderResponse {
        $prompt = trim($renderedPrompt);
        if ($prompt === '') {
            throw new AiPipelineException('AI_PROVIDER_PROMPT_EMPTY');
        }
        if ($outputSchema === []) {
            throw new AiPipelineException('AI_PROVIDER_OUTPUT_SCHEMA_EMPTY');
        }
        if (preg_match('/^[A-Za-z0-9_-]{1,64}$/', $schemaName) !== 1) {
            throw new AiPipelineException('AI_PROVIDER_SCHEMA_NAME_INVALID');
        }

        $config = $this->configuration->values();
        $body = [
            'model' => $config['model'],
            'input' => [[
                'role' => 'user',
                'content' => [[
                    'type' => 'input_text',
                    'text' => $prompt,
                ]],
            ]],
            'text' => [
                'format' => [
                    'type' => 'json_schema',
                    'name' => $schemaName,
                    'strict' => $config['strict_schema'],
                    'schema' => $outputSchema,
                ],
            ],
            'reasoning' => [
                'effort' => $config['reasoning_effort'],
            ],
            'max_output_tokens' => $config['max_output_tokens'],
            // Minimización de datos: el pipeline no necesita que OpenAI retenga
            // la Response para recuperarla después de una ejecución síncrona.
            'store' => false,
        ];

        if ($operationKey !== null && trim($operationKey) !== '') {
            $body['metadata'] = [
                'operation_key' => mb_substr(trim($operationKey), 0, 512),
            ];
        }

        try {
            $response = Http::baseUrl($config['base_url'])
                ->withToken($config['api_key'])
                ->acceptJson()
                ->asJson()
                ->connectTimeout($config['connect_timeout_seconds'])
                ->timeout($config['timeout_seconds'])
                ->post('/responses', $body);
        } catch (ConnectionException $e) {
            // No reintentamos automáticamente un POST cuyo resultado remoto es
            // desconocido: podría haber sido aceptado y facturado antes de que
            // la conexión local fallara.
            throw new AiPipelineException('AI_PROVIDER_TRANSPORT_AMBIGUOUS');
        }

        if ($response->failed()) {
            $this->throwForHttpFailure($response);
        }

        $json = $response->json();
        if (! is_array($json)) {
            throw new AiPipelineException('AI_PROVIDER_RESPONSE_INVALID');
        }
        if (($json['status'] ?? null) !== 'completed') {
            throw new AiPipelineException('AI_PROVIDER_RESPONSE_INCOMPLETE');
        }

        $responseId = trim((string) ($json['id'] ?? ''));
        $model = trim((string) ($json['model'] ?? $config['model']));
        if ($responseId === '' || $model === '') {
            throw new AiPipelineException('AI_PROVIDER_RESPONSE_METADATA_INVALID');
        }

        $text = $this->outputText($json);
        try {
            $payload = json_decode($text, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new AiPipelineException('AI_PROVIDER_OUTPUT_JSON_INVALID');
        }
        if (! is_array($payload) || array_is_list($payload)) {
            throw new AiPipelineException('AI_PROVIDER_OUTPUT_OBJECT_REQUIRED');
        }

        $usage = is_array($json['usage'] ?? null) ? $json['usage'] : [];
        $inputDetails = is_array($usage['input_tokens_details'] ?? null) ? $usage['input_tokens_details'] : [];
        $outputDetails = is_array($usage['output_tokens_details'] ?? null) ? $usage['output_tokens_details'] : [];

        return new StructuredProviderResponse(
            provider: 'openai',
            model: $model,
            responseId: $responseId,
            payload: $payload,
            inputTokens: max(0, (int) ($usage['input_tokens'] ?? 0)),
            cachedInputTokens: max(0, (int) ($inputDetails['cached_tokens'] ?? 0)),
            outputTokens: max(0, (int) ($usage['output_tokens'] ?? 0)),
            reasoningTokens: max(0, (int) ($outputDetails['reasoning_tokens'] ?? 0)),
            totalTokens: max(0, (int) ($usage['total_tokens'] ?? 0)),
        );
    }

    /** @param array<string,mixed> $json */
    private function outputText(array $json): string
    {
        foreach ((array) ($json['output'] ?? []) as $item) {
            if (! is_array($item) || ($item['type'] ?? null) !== 'message') {
                continue;
            }
            foreach ((array) ($item['content'] ?? []) as $content) {
                if (! is_array($content)) {
                    continue;
                }
                if (($content['type'] ?? null) === 'refusal') {
                    throw new AiPipelineException('AI_PROVIDER_REFUSAL');
                }
                if (($content['type'] ?? null) === 'output_text') {
                    $text = trim((string) ($content['text'] ?? ''));
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        throw new AiPipelineException('AI_PROVIDER_OUTPUT_TEXT_MISSING');
    }

    private function throwForHttpFailure(Response $response): never
    {
        $status = $response->status();
        $code = match (true) {
            in_array($status, [401, 403], true) => 'AI_PROVIDER_AUTH_FAILED',
            $status === 429 => 'AI_PROVIDER_RATE_LIMITED',
            $status === 408 || $status >= 500 => 'AI_PROVIDER_TEMPORARY_FAILURE',
            default => 'AI_PROVIDER_REQUEST_REJECTED',
        };

        throw new AiPipelineException($code);
    }
}
