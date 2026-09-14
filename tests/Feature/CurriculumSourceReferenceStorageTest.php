<?php

namespace Tests\Feature;

use App\Enums\RoleCode;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\User;
use App\Services\Curriculum\CurriculumImportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumSourceReferenceStorageTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_accepts_long_structured_source_reference_and_content_title(): void
    {
        $actor = User::factory()->withRole(RoleCode::Administrator)->create();
        $longTitle = str_repeat('Contenido curricular oficial extenso ', 10);

        $payload = [
            'schema_version' => 1,
            'curriculum' => [
                'code' => 'MX-NEM-PRIMARIA-STORAGE-TEST',
                'name' => 'Currículo de prueba de almacenamiento',
                'country_code' => 'MX',
                'educational_level' => 'primaria',
                'description' => 'Prueba técnica de almacenamiento de trazabilidad.',
            ],
            'version' => [
                'number' => 1,
                'label' => 'Fuentes oficiales extensas',
                'source_reference' => [
                    'publisher' => 'Secretaría de Educación Pública',
                    'edition' => 2024,
                    'legal_basis' => ['Acuerdo 14/08/22', 'Acuerdo 06/08/23', 'Acuerdo 08/08/23'],
                    'phase_sources' => [
                        'F3' => 'https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_3.pdf',
                        'F4' => 'https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_4.pdf',
                        'F5' => 'https://educacionbasica.sep.gob.mx/wp-content/uploads/2024/06/Programa_Sintetico_Fase_5.pdf',
                    ],
                ],
                'effective_from' => null,
                'effective_until' => null,
            ],
            'educational_phases' => [
                ['code' => 'F3', 'name' => 'Fase 3', 'sort_order' => 3],
            ],
            'grades' => [
                ['code' => 'G1', 'name' => 'Primer grado', 'phase_code' => 'F3', 'ordinal' => 1],
            ],
            'formative_fields' => [
                ['code' => 'LEN', 'name' => 'Lenguajes'],
            ],
            'curricular_contents' => [
                [
                    'code' => 'F3-LEN-C001',
                    'title' => $longTitle,
                    'full_text' => $longTitle,
                    'phase_code' => 'F3',
                    'field_code' => 'LEN',
                    'source_locator' => 'Programa Sintético Fase 3, Lenguajes, p. 24',
                ],
            ],
            'pdas' => [
                [
                    'code' => 'F3-G1-LEN-C001-P01',
                    'full_text' => 'PDA técnico para validar el almacenamiento.',
                    'content_code' => 'F3-LEN-C001',
                    'grade_code' => 'G1',
                    'source_locator' => 'Programa Sintético Fase 3, Lenguajes, p. 24',
                ],
            ],
            'articulating_axes' => [
                ['code' => 'AX-INCLUSION', 'name' => 'Inclusión'],
            ],
        ];

        $serialized = json_encode(
            $payload['version']['source_reference'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
        );
        $this->assertGreaterThan(255, strlen($serialized));
        $this->assertGreaterThan(255, mb_strlen($longTitle));
        $this->assertLessThanOrEqual(1024, mb_strlen($longTitle));

        $report = app(CurriculumImportService::class)->import($payload, $actor, dryRun: true);

        $this->assertSame([], array_map(fn ($error) => $error->code, $report->errors));
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, CurriculumVersion::count());
    }
}
