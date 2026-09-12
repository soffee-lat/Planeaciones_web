<?php

namespace App\Services\AI;

final class AdaptiveCorrectionSchema
{
    public const CONTRACT_VERSION = 'adaptive_correction_result_v1';

    /** @return array<string,mixed> */
    public function build(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            '$id' => 'https://planeaciones.local/schemas/adaptive-correction-result-v1.dynamic.json',
            'title' => 'AdaptiveCorrectionResultV1',
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['schema_version', 'source_version_id', 'patch'],
            'properties' => [
                'schema_version' => ['const' => self::CONTRACT_VERSION],
                'source_version_id' => ['type' => 'integer', 'minimum' => 1],
                'patch' => [
                    'type' => 'object',
                    'additionalProperties' => false,
                    'properties' => [
                        'planning' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'properties' => [
                                'title' => ['type' => 'string', 'minLength' => 1],
                                'project_name' => ['type' => ['string', 'null']],
                            ],
                        ],
                        'pedagogical_design' => [
                            'type' => 'object',
                            'additionalProperties' => false,
                            'required' => ['purpose', 'learning_goals', 'assessment_strategy', 'adaptation_considerations'],
                            'properties' => [
                                'purpose' => ['type' => 'string', 'minLength' => 1],
                                'learning_goals' => [
                                    'type' => 'array',
                                    'minItems' => 1,
                                    'items' => ['type' => 'string', 'minLength' => 1],
                                ],
                                'assessment_strategy' => ['type' => 'string', 'minLength' => 1],
                                'adaptation_considerations' => [
                                    'type' => 'array',
                                    'items' => ['type' => 'string', 'minLength' => 1],
                                ],
                            ],
                        ],
                        'template_fields' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                        'custom' => [
                            'type' => 'object',
                            'additionalProperties' => true,
                        ],
                    ],
                ],
            ],
        ];
    }
}
