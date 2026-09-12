<?php

namespace Tests\Unit;

use App\Models\FormatVersion;
use App\Services\Documents\GenericInstitutionalFieldResolver;
use PHPUnit\Framework\TestCase;

class GenericInstitutionalFieldResolverTest extends TestCase
{
    public function test_misma_etiqueta_en_zonas_distintas_no_colisiona(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'anchors' => [
                    [
                        'id' => 'c:10',
                        'target_id' => 'c:11',
                        'label' => 'Observaciones',
                        'suggested_path' => null,
                    ],
                    [
                        'id' => 'c:80',
                        'target_id' => 'c:81',
                        'label' => 'Observaciones',
                        'suggested_path' => null,
                    ],
                ],
                'placeholders' => [],
                'suggested_mapping' => ['placeholders' => []],
            ],
        ];

        $result = (new GenericInstitutionalFieldResolver())->augment($version, [
            'schema_version' => 2,
            'anchors' => [],
            'placeholders' => [],
            'fragments' => [],
            'custom_fields' => [],
            'ignored_zones' => [],
        ]);

        $this->assertCount(2, $result['custom_fields']);
        $this->assertArrayHasKey('c:10', $result['anchors']);
        $this->assertArrayHasKey('c:80', $result['anchors']);
        $this->assertNotSame($result['anchors']['c:10'], $result['anchors']['c:80']);
    }

    public function test_no_presupone_nombres_de_formato_y_respeta_zonas_manuales(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'anchors' => [
                    [
                        'id' => 'c:1',
                        'target_id' => 'c:2',
                        'label' => 'Producto comunitario esperado',
                        'suggested_path' => null,
                    ],
                    [
                        'id' => 'c:3',
                        'target_id' => 'c:4',
                        'label' => 'Vo.Bo. maestra titular',
                        'suggested_path' => null,
                    ],
                ],
                'placeholders' => [],
                'suggested_mapping' => ['placeholders' => []],
            ],
        ];

        $result = (new GenericInstitutionalFieldResolver())->augment($version, [
            'schema_version' => 2,
            'anchors' => [],
            'placeholders' => [],
            'fragments' => [],
            'custom_fields' => [],
            'ignored_zones' => ['c:3', 'c:4'],
        ]);

        $this->assertCount(1, $result['custom_fields']);
        $this->assertArrayHasKey('c:1', $result['anchors']);
        $this->assertArrayNotHasKey('c:3', $result['anchors']);
        $this->assertSame('Producto comunitario esperado', array_values($result['custom_fields'])[0]['label']);
    }
}
