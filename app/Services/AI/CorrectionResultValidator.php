<?php

namespace App\Services\AI;

use App\Data\AI\CorrectionResult;
use App\Exceptions\AiContractException;

final class CorrectionResultValidator
{
    public const CONTRACT_VERSION = 'correction_result_v1';

    public function __construct(
        private JsonSchemaSubsetValidator $schemaValidator,
        private AdaptiveCorrectionSchema $adaptiveSchema,
    ) {}

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): CorrectionResult
    {
        $schemaVersion = (string) ($payload['schema_version'] ?? '');
        $schema = $schemaVersion === AdaptiveCorrectionSchema::CONTRACT_VERSION
            ? $this->adaptiveSchema->build()
            : $this->legacySchema();

        if (array_key_exists('patch', $payload) && $payload['patch'] === []) {
            throw new AiContractException('AI_CORRECTION_PATCH_EMPTY', '$.patch');
        }

        $this->schemaValidator->validate($payload, $schema);

        $sourceVersionId = (int) ($payload['source_version_id'] ?? 0);
        $patch = $payload['patch'] ?? null;
        if ($sourceVersionId < 1) {
            throw new AiContractException('AI_CORRECTION_SOURCE_VERSION_INVALID', '$.source_version_id');
        }
        if (! is_array($patch) || array_is_list($patch) || $patch === []) {
            throw new AiContractException('AI_CORRECTION_PATCH_EMPTY', '$.patch');
        }
        if (isset($patch['planning']) && is_array($patch['planning']) && $patch['planning'] === []) {
            throw new AiContractException('AI_CORRECTION_PLANNING_PATCH_EMPTY', '$.patch.planning');
        }
        if ($schemaVersion === AdaptiveCorrectionSchema::CONTRACT_VERSION) {
            foreach (['template_fields', 'custom'] as $root) {
                if (isset($patch[$root]) && (! is_array($patch[$root]) || array_is_list($patch[$root]) || $patch[$root] === [])) {
                    throw new AiContractException('AI_CORRECTION_ADAPTIVE_PATCH_INVALID', '$.patch.' . $root);
                }
            }
        }

        return new CorrectionResult($sourceVersionId, $patch);
    }

    /** @return array<string,mixed> */
    private function legacySchema(): array
    {
        $schema = json_decode(
            file_get_contents(resource_path('schemas/ai/correction_result_v1.schema.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (! is_array($schema)) {
            throw new AiContractException('AI_SCHEMA_INVALID', '$', self::CONTRACT_VERSION);
        }

        return $schema;
    }
}
