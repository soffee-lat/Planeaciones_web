<?php

namespace App\Data\AI;

/**
 * Resultado normalizado de un proveedor IA estructurado.
 *
 * El payload ya es JSON decodificado, pero sigue sujeto a los validadores de
 * dominio del pipeline antes de persistir cualquier cambio en una planeación.
 */
final readonly class StructuredProviderResponse
{
    /**
     * @param array<string,mixed> $payload
     */
    public function __construct(
        public string $provider,
        public string $model,
        public string $responseId,
        public array $payload,
        public int $inputTokens,
        public int $cachedInputTokens,
        public int $outputTokens,
        public int $reasoningTokens,
        public int $totalTokens,
    ) {}
}
