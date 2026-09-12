<?php

namespace App\Services\AI;

use App\Data\Planning\AdaptiveGeneratedPlan;
use App\Exceptions\AiContractException;

final class AdaptiveGeneratedPlanValidator
{
    public function __construct(
        private JsonSchemaSubsetValidator $schemaValidator,
        private FormatAwareGenerationSchema $generationSchema,
    ) {}

    /** @param array<string,mixed> $payload @param array<string,mixed> $formatContext */
    public function validate(array $payload, array $formatContext): AdaptiveGeneratedPlan
    {
        if ((int) ($formatContext['schema_version'] ?? 0) < 3
            || ($formatContext['generation_contract'] ?? null) !== FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION) {
            throw new AiContractException('ADAPTIVE_GENERATION_FORMAT_CONTEXT_REQUIRED', '$');
        }

        $schema = $this->generationSchema->extend([], $formatContext);
        $this->schemaValidator->validate($payload, $schema);
        $this->validateCoverage($payload, $formatContext);

        return new AdaptiveGeneratedPlan($payload);
    }

    /** @param array<string,mixed> $payload @param array<string,mixed> $formatContext */
    private function validateCoverage(array $payload, array $formatContext): void
    {
        $snapshot = is_array($formatContext['curriculum_snapshot'] ?? null)
            ? $formatContext['curriculum_snapshot']
            : [];
        $expected = [];
        foreach ((array) ($snapshot['pdas'] ?? []) as $pda) {
            if (is_array($pda) && trim((string) ($pda['code'] ?? '')) !== '') {
                $expected[(string) $pda['code']] = true;
            }
        }

        // El contexto normalmente no duplica el snapshot curricular. Cuando no
        // esté presente, la cobertura exacta se valida durante el ensamblado con
        // el RequestInputVersion congelado.
        if ($expected === []) {
            return;
        }

        $seen = [];
        foreach ((array) data_get($payload, 'core.pda_coverage', []) as $index => $coverage) {
            $code = is_array($coverage) ? trim((string) ($coverage['pda_code'] ?? '')) : '';
            if ($code === '' || ! isset($expected[$code])) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_NOT_ALLOWED', '$.core.pda_coverage[' . $index . '].pda_code', $code);
            }
            if (isset($seen[$code])) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_DUPLICATE', '$.core.pda_coverage[' . $index . '].pda_code', $code);
            }
            $seen[$code] = true;
        }

        if (array_keys($seen) !== array_keys($expected)) {
            $missing = array_values(array_diff(array_keys($expected), array_keys($seen)));
            if ($missing !== []) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_NOT_COVERED', '$.core.pda_coverage', $missing[0]);
            }
        }
    }
}
