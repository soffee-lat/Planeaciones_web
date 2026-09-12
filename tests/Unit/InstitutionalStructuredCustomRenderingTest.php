<?php

namespace Tests\Unit;

use App\Services\Documents\InstitutionalFormatMapping;
use PHPUnit\Framework\TestCase;

class InstitutionalStructuredCustomRenderingTest extends TestCase
{
    public function test_serializa_una_matriz_desconocida_sin_suponer_sus_columnas(): void
    {
        $values = (new InstitutionalFormatMapping())->values([
            'custom' => [
                'matriz_multigrado' => [
                    ['grado' => '3º', 'pda' => 'Lee y comprende cuentos.'],
                    ['grado' => '4º', 'pda' => 'Planea, escribe y revisa cuentos.'],
                ],
            ],
        ], [
            'anchors' => ['c:10' => 'custom.matriz_multigrado'],
            'placeholders' => [],
            'fragments' => [],
        ]);

        $rendered = $values['anchors']['c:10'];
        $this->assertStringContainsString('grado: 3º', $rendered);
        $this->assertStringContainsString('pda: Lee y comprende cuentos.', $rendered);
        $this->assertStringContainsString('grado: 4º', $rendered);
        $this->assertStringContainsString('pda: Planea, escribe y revisa cuentos.', $rendered);
    }
}
