<?php

namespace App\Services\AI;

use App\Data\AI\AuditFinding;
use App\Data\AI\AuditResult;
use App\Exceptions\AiContractException;

final class AuditResultValidator
{
    public const CONTRACT_VERSION = 'audit_result_v1';

    /** @var list<string> */
    private const CODES = [
        'SCHEMA',
        'CURRICULUM_REFERENCE',
        'CURRICULUM_COVERAGE',
        'PEDAGOGICAL_ALIGNMENT',
        'TIME_CONSISTENCY',
        'ASSESSMENT_ALIGNMENT',
        'AGE_APPROPRIATENESS',
        'GROUP_CONTEXT',
        'MATERIAL_FEASIBILITY',
        'UNSUPPORTED_ASSUMPTION',
        'SAFETY_OR_INCLUSION',
        'LANGUAGE_QUALITY',
    ];

    /** @var list<string> */
    private const SEVERITIES = ['info', 'low', 'medium', 'high', 'critical'];

    public function __construct(private JsonSchemaSubsetValidator $schemaValidator) {}

    /** @param array<string,mixed> $payload */
    public function validate(array $payload): AuditResult
    {
        $schema = json_decode(
            file_get_contents(resource_path('schemas/ai/audit_result_v1.schema.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        if (! is_array($schema)) {
            throw new AiContractException('AI_SCHEMA_INVALID', '$', self::CONTRACT_VERSION);
        }

        $this->schemaValidator->validate($payload, $schema);

        $passed = (bool) $payload['passed'];
        $rawFindings = $payload['findings'];
        if (! is_array($rawFindings)) {
            throw new AiContractException('AI_AUDIT_FINDINGS_INVALID', '$.findings');
        }
        if ($passed && $rawFindings !== []) {
            throw new AiContractException('AI_AUDIT_PASSED_WITH_FINDINGS', '$.findings');
        }
        if (! $passed && $rawFindings === []) {
            throw new AiContractException('AI_AUDIT_FAILED_WITHOUT_FINDINGS', '$.findings');
        }

        $seen = [];
        $findings = [];
        foreach ($rawFindings as $index => $finding) {
            if (! is_array($finding) || array_is_list($finding)) {
                throw new AiContractException('AI_AUDIT_FINDING_INVALID', '$.findings[' . $index . ']');
            }

            $code = (string) $finding['code'];
            $severity = (string) $finding['severity'];
            $jsonPath = (string) $finding['json_path'];
            if (! in_array($code, self::CODES, true)) {
                throw new AiContractException('AI_AUDIT_FINDING_CODE_UNSUPPORTED', '$.findings[' . $index . '].code', $code);
            }
            if (! in_array($severity, self::SEVERITIES, true)) {
                throw new AiContractException('AI_AUDIT_FINDING_SEVERITY_UNSUPPORTED', '$.findings[' . $index . '].severity', $severity);
            }
            if ($jsonPath !== '$' && ! str_starts_with($jsonPath, '/')) {
                throw new AiContractException('AI_AUDIT_FINDING_PATH_INVALID', '$.findings[' . $index . '].json_path', $jsonPath);
            }

            $fingerprint = $code . "\0" . $jsonPath . "\0" . (string) $finding['explanation'];
            if (isset($seen[$fingerprint])) {
                throw new AiContractException('AI_AUDIT_FINDING_DUPLICATE', '$.findings[' . $index . ']');
            }
            $seen[$fingerprint] = true;

            $findings[] = new AuditFinding(
                code: $code,
                severity: $severity,
                jsonPath: $jsonPath,
                explanation: trim((string) $finding['explanation']),
                expectedCorrection: trim((string) $finding['expected_correction']),
            );
        }

        return new AuditResult($passed, $findings);
    }
}
