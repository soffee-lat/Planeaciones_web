<?php

namespace Tests\Unit;

use App\Exceptions\AiContractException;
use App\Services\AI\CorrectionResultValidator;
use Tests\TestCase;

class CorrectionResultValidatorTest extends TestCase
{
    public function test_acepta_patch_minimo_de_titulo(): void
    {
        $result = app(CorrectionResultValidator::class)->validate([
            'schema_version' => 'correction_result_v1',
            'source_version_id' => 7,
            'patch' => ['planning' => ['title' => 'Título corregido']],
        ]);

        $this->assertSame(7, $result->sourceVersionId);
        $this->assertSame('Título corregido', $result->patch['planning']['title']);
    }

    public function test_rechaza_root_inmutable(): void
    {
        $this->expectException(AiContractException::class);
        app(CorrectionResultValidator::class)->validate([
            'schema_version' => 'correction_result_v1',
            'source_version_id' => 1,
            'patch' => ['curricular_alignment' => ['pdas' => []]],
        ]);
    }

    public function test_rechaza_patch_vacio(): void
    {
        try {
            app(CorrectionResultValidator::class)->validate([
                'schema_version' => 'correction_result_v1',
                'source_version_id' => 1,
                'patch' => [],
            ]);
            $this->fail('Patch vacío debe rechazarse.');
        } catch (AiContractException $e) {
            $this->assertContains($e->errorCode, ['AI_SCHEMA_MIN_PROPERTIES', 'AI_CORRECTION_PATCH_EMPTY']);
        }
    }

    public function test_rechaza_planning_vacio(): void
    {
        $this->expectException(AiContractException::class);
        app(CorrectionResultValidator::class)->validate([
            'schema_version' => 'correction_result_v1',
            'source_version_id' => 1,
            'patch' => ['planning' => []],
        ]);
    }

    public function test_rechaza_campo_server_de_planning(): void
    {
        $this->expectException(AiContractException::class);
        app(CorrectionResultValidator::class)->validate([
            'schema_version' => 'correction_result_v1',
            'source_version_id' => 1,
            'patch' => ['planning' => ['starts_on' => '2030-01-01']],
        ]);
    }

    public function test_rechaza_source_version_no_positivo(): void
    {
        $this->expectException(AiContractException::class);
        app(CorrectionResultValidator::class)->validate([
            'schema_version' => 'correction_result_v1',
            'source_version_id' => 0,
            'patch' => ['planning' => ['title' => 'X']],
        ]);
    }

    public function test_schema_y_ejemplo_publicados_son_coherentes(): void
    {
        $example = json_decode(
            file_get_contents(resource_path('schemas/ai/examples/correction_result_v1.example.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $result = app(CorrectionResultValidator::class)->validate($example);

        $this->assertSame('correction_result_v1', $result->toArray()['schema_version']);
        $this->assertNotEmpty($result->patch);
    }
}
