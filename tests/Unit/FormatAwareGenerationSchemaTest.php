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

    public function test_admite_tablas_y_bloques_repetibles_sin_conocer_el_formato(): void
    {
        $base = [
            'type' => 'object',
            'required' => [],
            'properties' => [],
        ];

        $schema = (new FormatAwareGenerationSchema())->extend($base, [
            'custom_fields' => [
                ['key' => 'matriz_multigrado', 'type' => 'table', 'source' => 'ai', 'required' => true],
                ['key' => 'bloques_del_formato', 'type' => 'repeating_block', 'source' => 'ai', 'required' => true],
                ['key' => 'fecha_especial', 'type' => 'date', 'source' => 'ai', 'required' => false],
            ],
        ]);

        $custom = $schema['properties']['custom']['properties'];
        $this->assertSame('array', $custom['matriz_multigrado']['type']);
        $this->assertSame('object', $custom['matriz_multigrado']['items']['type']);
        $this->assertSame('array', $custom['bloques_del_formato']['type']);
        $this->assertSame('date', $custom['fecha_especial']['format']);
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
