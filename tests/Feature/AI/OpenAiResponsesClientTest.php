<?php

namespace Tests\Feature\AI;

use App\Exceptions\AiPipelineException;
use App\Services\AI\Providers\OpenAiResponsesClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenAiResponsesClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config([
            'ai.api.openai.base_url' => 'https://api.openai.test/v1',
            'ai.api.openai.api_key' => 'test-key-not-secret',
            'ai.api.openai.model' => 'gpt-5.6-luna',
            'ai.api.openai.timeout_seconds' => 45,
            'ai.api.openai.connect_timeout_seconds' => 5,
            'ai.api.openai.max_output_tokens' => 4096,
            'ai.api.openai.reasoning_effort' => 'low',
            'ai.api.openai.strict_schema' => false,
        ]);
    }

    public function test_responses_client_envia_schema_dinamico_y_normaliza_payload_y_uso(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'id' => 'resp_test_123',
                'status' => 'completed',
                'model' => 'gpt-5.6-luna',
                'output' => [[
                    'type' => 'message',
                    'content' => [[
                        'type' => 'output_text',
                        'text' => '{"contract_version":"adaptive_template_generation_v1","core":{"title":"Prueba"}}',
                    ]],
                ]],
                'usage' => [
                    'input_tokens' => 1200,
                    'input_tokens_details' => ['cached_tokens' => 300],
                    'output_tokens' => 240,
                    'output_tokens_details' => ['reasoning_tokens' => 40],
                    'total_tokens' => 1440,
                ],
            ], 200),
        ]);

        $schema = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['contract_version', 'core'],
            'properties' => [
                'contract_version' => ['type' => 'string'],
                'core' => ['type' => 'object'],
            ],
        ];

        $result = app(OpenAiResponsesClient::class)->respond(
            'Genera una planeación y devuelve únicamente el contrato solicitado.',
            $schema,
            'adaptive_generation_v1',
            'planning-request:22:generation:input:3:prompt:9',
        );

        $this->assertSame('openai', $result->provider);
        $this->assertSame('gpt-5.6-luna', $result->model);
        $this->assertSame('resp_test_123', $result->responseId);
        $this->assertSame('adaptive_template_generation_v1', $result->payload['contract_version']);
        $this->assertSame(1200, $result->inputTokens);
        $this->assertSame(300, $result->cachedInputTokens);
        $this->assertSame(240, $result->outputTokens);
        $this->assertSame(40, $result->reasoningTokens);
        $this->assertSame(1440, $result->totalTokens);

        Http::assertSent(function (Request $request) use ($schema): bool {
            $body = $request->data();

            return $request->method() === 'POST'
                && $request->url() === 'https://api.openai.test/v1/responses'
                && $request->hasHeader('Authorization', 'Bearer test-key-not-secret')
                && ($body['model'] ?? null) === 'gpt-5.6-luna'
                && ($body['store'] ?? null) === false
                && ($body['max_output_tokens'] ?? null) === 4096
                && data_get($body, 'reasoning.effort') === 'low'
                && data_get($body, 'text.format.type') === 'json_schema'
                && data_get($body, 'text.format.name') === 'adaptive_generation_v1'
                && data_get($body, 'text.format.strict') === false
                && data_get($body, 'text.format.schema') === $schema
                && data_get($body, 'metadata.operation_key') === 'planning-request:22:generation:input:3:prompt:9';
        });
    }

    public function test_responses_client_clasifica_rate_limit_sin_exponer_respuesta_del_proveedor(): void
    {
        Http::preventStrayRequests();
        Http::fake([
            'https://api.openai.test/v1/responses' => Http::response([
                'error' => ['message' => 'provider detail that must not escape'],
            ], 429),
        ]);

        try {
            app(OpenAiResponsesClient::class)->respond('Prompt', ['type' => 'object'], 'result');
            $this->fail('Expected AiPipelineException.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_PROVIDER_RATE_LIMITED', $e->errorCode);
            $this->assertSame('AI_PROVIDER_RATE_LIMITED', $e->getMessage());
            $this->assertStringNotContainsString('provider detail', $e->getMessage());
        }
    }

    public function test_responses_client_no_reintenta_timeout_ambiguo(): void
    {
        Http::preventStrayRequests();
        $attempts = 0;
        Http::fake(function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('simulated timeout');
        });

        try {
            app(OpenAiResponsesClient::class)->respond('Prompt', ['type' => 'object'], 'result');
            $this->fail('Expected AiPipelineException.');
        } catch (AiPipelineException $e) {
            $this->assertSame('AI_PROVIDER_TRANSPORT_AMBIGUOUS', $e->errorCode);
            $this->assertSame(1, $attempts);
        }
    }

    public function test_openai_configuration_exige_api_key_sin_hacer_request(): void
    {
        config(['ai.api.openai.api_key' => '']);
        Http::preventStrayRequests();

        $this->expectException(AiPipelineException::class);
        $this->expectExceptionMessage('AI_OPENAI_API_KEY_MISSING');

        app(OpenAiResponsesClient::class)->respond('Prompt', ['type' => 'object'], 'result');
    }
}
