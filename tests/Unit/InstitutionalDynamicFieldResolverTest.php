<?php

namespace Tests\Unit;

use App\Models\FormatVersion;
use App\Services\Documents\InstitutionalDynamicFieldResolver;
use PHPUnit\Framework\TestCase;

class InstitutionalDynamicFieldResolverTest extends TestCase
{
    public function test_crea_campo_custom_para_etiqueta_institucional_desconocida(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'anchors' => [[
                    'id' => 'c:1',
                    'target_id' => 'c:2',
                    'label' => 'Vinculación con las familias',
                    'suggested_path' => null,
                    'current_value_excerpt' => 'Las familias enviarán una fotografía relacionada con el proyecto.',
                ]],
                'placeholders' => [],
                'suggested_mapping' => ['placeholders' => []],
            ],
        ];

        $mapping = [
            'schema_version' => 2,
            'anchors' => [],
            'placeholders' => [],
            'fragments' => [],
            'custom_fields' => [],
            'ignored_zones' => [],
        ];

        $result = (new InstitutionalDynamicFieldResolver())->augment($version, $mapping);

        $this->assertCount(1, $result['custom_fields']);
        $key = array_key_first($result['custom_fields']);
        $this->assertNotNull($key);
        $this->assertSame('Vinculación con las familias', $result['custom_fields'][$key]['label']);
        $this->assertSame('custom.' . $key, $result['anchors']['c:1']);
    }

    public function test_no_convierte_en_custom_un_campo_estandar_o_ignorado(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'anchors' => [
                    [
                        'id' => 'c:1',
                        'target_id' => 'c:2',
                        'label' => 'Propósito',
                        'suggested_path' => 'pedagogical_design.purpose',
                    ],
                    [
                        'id' => 'c:3',
                        'target_id' => 'c:4',
                        'label' => 'Firma del director',
                        'suggested_path' => null,
                    ],
                ],
                'placeholders' => [],
                'suggested_mapping' => ['placeholders' => []],
            ],
        ];

        $mapping = [
            'schema_version' => 2,
            'anchors' => [],
            'placeholders' => [],
            'fragments' => [],
            'custom_fields' => [],
            'ignored_zones' => ['c:3'],
        ];

        $result = (new InstitutionalDynamicFieldResolver())->augment($version, $mapping);

        $this->assertSame([], $result['custom_fields']);
        $this->assertSame([], $result['anchors']);
    }
}
