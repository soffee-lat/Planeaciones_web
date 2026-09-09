<?php

namespace Tests\Unit;

use App\Exceptions\AiContractException;
use App\Services\AI\AuditResultValidator;
use Tests\TestCase;

class AuditResultValidatorTest extends TestCase
{
    public function test_acepta_resultado_aprobado_sin_hallazgos(): void
    {
        $result = app(AuditResultValidator::class)->validate([
            'schema_version' => 'audit_result_v1',
            'passed' => true,
            'findings' => [],
        ]);

        $this->assertTrue($result->passed);
        $this->assertSame([], $result->findings);
        $this->assertSame('audit_result_v1', $result->toArray()['schema_version']);
    }

    public function test_acepta_resultado_fallido_con_hallazgo_estructurado(): void
    {
        $result = app(AuditResultValidator::class)->validate($this->failedPayload());

        $this->assertFalse($result->passed);
        $this->assertCount(1, $result->findings);
        $this->assertSame('CURRICULUM_COVERAGE', $result->findings[0]->code);
        $this->assertSame('/sessions/0', $result->findings[0]->jsonPath);
    }

    public function test_rechaza_passed_con_hallazgos(): void
    {
        $payload = $this->failedPayload();
        $payload['passed'] = true;

        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('AI_AUDIT_PASSED_WITH_FINDINGS');
        app(AuditResultValidator::class)->validate($payload);
    }

    public function test_rechaza_failed_sin_hallazgos(): void
    {
        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('AI_AUDIT_FAILED_WITHOUT_FINDINGS');
        app(AuditResultValidator::class)->validate([
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [],
        ]);
    }

    public function test_rechaza_categoria_fuera_del_contrato(): void
    {
        $payload = $this->failedPayload();
        $payload['findings'][0]['code'] = 'INVENTED_CATEGORY';

        $this->expectException(AiContractException::class);
        app(AuditResultValidator::class)->validate($payload);
    }

    public function test_rechaza_ruta_no_json_pointer(): void
    {
        $payload = $this->failedPayload();
        $payload['findings'][0]['json_path'] = 'sessions.0';

        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('AI_AUDIT_FINDING_PATH_INVALID');
        app(AuditResultValidator::class)->validate($payload);
    }

    public function test_rechaza_hallazgo_duplicado(): void
    {
        $payload = $this->failedPayload();
        $payload['findings'][] = $payload['findings'][0];

        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('AI_AUDIT_FINDING_DUPLICATE');
        app(AuditResultValidator::class)->validate($payload);
    }

    /** @return array<string,mixed> */
    private function failedPayload(): array
    {
        return [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [[
                'code' => 'CURRICULUM_COVERAGE',
                'severity' => 'high',
                'json_path' => '/sessions/0',
                'explanation' => 'El PDA seleccionado no queda cubierto de forma suficiente.',
                'expected_correction' => 'Ajustar la sesión para cubrir explícitamente el PDA.',
            ]],
        ];
    }
}
