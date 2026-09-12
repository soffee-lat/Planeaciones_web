<?php

namespace Tests\Unit;

use App\Models\FormatVersion;
use App\Services\Documents\InstitutionalTemplateContract;
use PHPUnit\Framework\TestCase;

class InstitutionalTemplateContractTest extends TestCase
{
    public function test_contrato_sigue_el_documento_y_no_un_esquema_escolar_global(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'source_content_mode' => 'filled_example',
                'anchors' => [
                    [
                        'id' => 'c:4',
                        'label' => 'Problemática y/o tema de interés',
                        'current_value_excerpt' => 'El grupo muestra poco interés por la lectura.',
                    ],
                    [
                        'id' => 'c:22',
                        'label' => 'Entregable',
                        'current_value_excerpt' => 'Cuento y dibujo',
                    ],
                ],
                'placeholders' => [],
            ],
        ];

        $mapping = [
            'schema_version' => 2,
            'anchors' => [
                'c:4' => 'custom.problematica_a1b2c3d4',
                'c:22' => 'custom.entregable_e5f6a7b8',
            ],
            'placeholders' => [],
            'fragments' => [],
            'ignored_zones' => ['c:99'],
            'custom_fields' => [
                'problematica_a1b2c3d4' => [
                    'label' => 'Problemática y/o tema de interés',
                    'type' => 'long_text',
                    'instruction' => 'Explica la situación que da origen al proyecto.',
                ],
                'entregable_e5f6a7b8' => [
                    'label' => 'Entregable',
                    'type' => 'list',
                    'instruction' => 'Define los productos observables de la sesión.',
                ],
            ],
        ];

        $contract = (new InstitutionalTemplateContract())->build($version, $mapping);

        $this->assertSame('user_docx', $contract['authority']);
        $this->assertSame('filled_example', $contract['source_content_mode']);
        $this->assertCount(2, $contract['fields']);
        $this->assertSame('Problemática y/o tema de interés', $contract['fields'][0]['label']);
        $this->assertSame('ai', $contract['fields'][0]['source']);
        $this->assertSame('El grupo muestra poco interés por la lectura.', $contract['fields'][0]['example']);
        $this->assertSame('list', $contract['fields'][1]['type']);
        $this->assertSame(['c:99'], $contract['ignored_zones']);
    }

    public function test_datos_curriculares_y_de_sistema_no_se_piden_a_la_ia(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'source_content_mode' => 'blank_template',
                'anchors' => [
                    ['id' => 'c:1', 'label' => 'PDA'],
                    ['id' => 'c:2', 'label' => 'Grupo'],
                    ['id' => 'c:3', 'label' => 'Propósito'],
                ],
                'placeholders' => [],
            ],
        ];

        $contract = (new InstitutionalTemplateContract())->build($version, [
            'schema_version' => 2,
            'anchors' => [
                'c:1' => 'curricular_alignment.pdas',
                'c:2' => 'context.group_name',
                'c:3' => 'pedagogical_design.purpose',
            ],
            'placeholders' => [],
            'fragments' => [],
            'ignored_zones' => [],
            'custom_fields' => [],
        ]);

        $this->assertSame('curriculum', $contract['fields'][0]['source']);
        $this->assertFalse($contract['fields'][0]['required']);
        $this->assertSame('system', $contract['fields'][1]['source']);
        $this->assertFalse($contract['fields'][1]['required']);
        $this->assertSame('ai', $contract['fields'][2]['source']);
        $this->assertTrue($contract['fields'][2]['required']);
    }
}
