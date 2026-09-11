<?php

namespace Tests\Unit;

use App\Services\AI\FormatAwareGenerationSchema;
use PHPUnit\Framework\TestCase;

class FormatAwareGenerationSchemaTest extends TestCase
{
    public function test_extiende_solo_con_campos_custom_generables_por_ia(): void
    {
        $base = [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['contract_version'],
            'properties' => [
                'contract_version' => ['const' => 'generated_plan_draft_v1'],
            ],
        ];
        $context = [
            'custom_fields' => [
                [
                    'key' => 'vinculacion_familias',
                    'type' => 'long_text',
                    'source' => 'ai',
                    'required' => true,
                ],
                [
                    'key' => 'evidencias_extra',
                    'type' => 'list',
                    'source' => 'ai',
                    'required' => true,
                ],
                [
                    'key' => 'firma_director',
                    'type' => 'text',
                    'source' => 'manual',
                    'required' => false,
                ],
            ],
        ];

        $schema = (new FormatAwareGenerationSchema())->extend($base, $context);

        $this->assertContains('custom', $schema['required']);
        $this->assertSame(
            ['evidencias_extra', 'vinculacion_familias'],
            $schema['properties']['custom']['required'],
        );
        $this->assertArrayHasKey('vinculacion_familias', $schema['properties']['custom']['properties']);
        $this->assertArrayHasKey('evidencias_extra', $schema['properties']['custom']['properties']);
        $this->assertArrayNotHasKey('firma_director', $schema['properties']['custom']['properties']);
        $this->assertSame('array', $schema['properties']['custom']['properties']['evidencias_extra']['type']);
    }

    public function test_sin_formato_dinamico_conserva_esquema_base(): void
    {
        $base = [
            'type' => 'object',
            'required' => ['contract_version'],
            'properties' => ['contract_version' => ['const' => 'generated_plan_draft_v1']],
        ];

        $this->assertSame(
            $base,
            (new FormatAwareGenerationSchema())->extend($base, ['custom_fields' => []]),
        );
    }
}
