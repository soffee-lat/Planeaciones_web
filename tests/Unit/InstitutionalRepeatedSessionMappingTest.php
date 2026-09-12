<?php

namespace Tests\Unit;

use App\Models\FormatVersion;
use App\Services\Documents\InstitutionalDynamicFieldResolver;
use App\Services\Documents\InstitutionalFormatFieldCatalog;
use App\Services\Documents\InstitutionalFormatMapping;
use PHPUnit\Framework\TestCase;

class InstitutionalRepeatedSessionMappingTest extends TestCase
{
    public function test_no_infiere_sesiones_indexadas_desde_la_forma_de_un_docx_especifico(): void
    {
        $version = new FormatVersion();
        $version->validation_report = [
            'analysis' => [
                'document_zones' => $this->dailyZones(),
                'anchors' => $this->dailyAnchors(),
                'placeholders' => [],
                'suggested_mapping' => ['placeholders' => []],
            ],
        ];

        $mapping = [
            'schema_version' => 2,
            'anchors' => [
                'c:10' => 'resources',
                'c:13' => 'sessions',
                'c:14' => 'sessions',
                'c:15' => 'sessions',
            ],
            'placeholders' => [],
            'fragments' => [],
            'custom_fields' => [],
            'ignored_zones' => [],
        ];

        $result = (new InstitutionalDynamicFieldResolver())->augment($version, $mapping);

        $this->assertSame('resources', $result['anchors']['c:10']);
        $this->assertSame('sessions', $result['anchors']['c:13']);
        $this->assertSame('sessions', $result['anchors']['c:14']);
        $this->assertSame('sessions', $result['anchors']['c:15']);

        $this->assertArrayNotHasKey('c:4', $result['anchors']);
        $this->assertArrayNotHasKey('c:16', $result['anchors']);
        $this->assertArrayNotHasKey('c:30', $result['anchors']);
        $this->assertArrayNotHasKey('c:42', $result['anchors']);

        $this->assertArrayHasKey('c:24', $result['anchors']);
        $this->assertArrayHasKey('c:50', $result['anchors']);
        $this->assertArrayHasKey('c:51', $result['anchors']);
        $this->assertStringStartsWith('custom.', $result['anchors']['c:24']);
        $this->assertStringStartsWith('custom.', $result['anchors']['c:50']);
        $this->assertStringStartsWith('custom.', $result['anchors']['c:51']);
        $this->assertNotSame($result['anchors']['c:24'], $result['anchors']['c:50']);

        foreach ($result['anchors'] as $path) {
            $this->assertFalse(str_starts_with($path, 'sessions.0.'));
            $this->assertFalse(str_starts_with($path, 'sessions.1.'));
        }

        $this->assertSame([], $result['fragments']);
        $this->assertCount(3, $result['custom_fields']);
    }

    public function test_resuelve_campos_por_sesion_sin_mezclar_los_dias(): void
    {
        $mapping = new InstitutionalFormatMapping();
        $canonical = [
            'context' => ['group_name' => 'K'],
            'curricular_alignment' => [
                'grade' => ['name' => 'SEGUNDO'],
                'fields' => [
                    ['code' => 'F1', 'name' => 'DE LO HUMANO A LO COMUNITARIO'],
                    ['code' => 'F2', 'name' => 'SABERES Y PENSAMIENTO CIENTÍFICO'],
                ],
                'contents' => [
                    ['code' => 'C1', 'full_text' => 'Construcción de la identidad personal.'],
                    ['code' => 'C2', 'full_text' => 'Los saberes numéricos como herramienta.'],
                ],
                'pdas' => [
                    ['code' => 'P1', 'full_text' => 'Reconoce algunos rasgos de su identidad.'],
                    ['code' => 'P2', 'full_text' => 'Usa números con distintos propósitos.'],
                ],
                'articulating_axes' => [
                    ['code' => 'A1', 'name' => 'INCLUSIÓN'],
                    ['code' => 'A2', 'name' => 'PENSAMIENTO CRÍTICO'],
                ],
            ],
            'sessions' => [
                $this->session('2026-09-07', 'Conociendo mi cuerpo', 'F1', 'C1', 'P1', 'A1', 'Observarse frente al espejo.', 'Dibujar su cuerpo.', 'Compartir lo aprendido.', 'E1', 'I01'),
                $this->session('2026-09-08', 'Contamos con el cuerpo', 'F2', 'C2', 'P2', 'A2', 'Contar ojos y manos.', 'Jugar con el dado corporal.', 'Completar expresiones numéricas.', 'E2', 'I02'),
            ],
            'assessment_plan' => [
                'instruments' => [
                    ['id' => 'I01', 'name' => 'Diario de observación'],
                    ['id' => 'I02', 'name' => 'Lista de cotejo'],
                ],
            ],
            'resources' => [
                'physical_materials' => ['espejos', 'tarjetas con números'],
                'digital_resources' => ['YouTube'],
            ],
        ];

        $values = $mapping->values($canonical, [
            'anchors' => [
                'c:1' => 'sessions.0.fields',
                'c:2' => 'sessions.1.fields',
                'c:3' => 'sessions.0.opening',
                'c:4' => 'sessions.1.opening',
                'c:5' => 'sessions.1.evidence',
                'c:6' => 'sessions.1.instruments',
                'c:7' => 'sessions.0.resources.digital',
                'c:8' => 'sessions.0.date',
            ],
            'placeholders' => [],
            'fragments' => [],
        ]);

        $this->assertSame('DE LO HUMANO A LO COMUNITARIO', $values['anchors']['c:1']);
        $this->assertSame('SABERES Y PENSAMIENTO CIENTÍFICO', $values['anchors']['c:2']);
        $this->assertSame('Observarse frente al espejo.', $values['anchors']['c:3']);
        $this->assertSame('Contar ojos y manos.', $values['anchors']['c:4']);
        $this->assertSame('E2', $values['anchors']['c:5']);
        $this->assertSame('Lista de cotejo', $values['anchors']['c:6']);
        $this->assertSame('YouTube', $values['anchors']['c:7']);
        $this->assertStringContainsString('07 DE SEPTIEMBRE', $values['anchors']['c:8']);
    }

    public function test_catalogo_reconoce_etiquetas_inline_del_formato_real(): void
    {
        $catalog = new InstitutionalFormatFieldCatalog();

        $this->assertSame('planning.project_name', $catalog->suggest('TITULO DEL PROYECTO')['path']);
        $this->assertSame('planning.starts_on', $catalog->suggest('FECHA')['path']);
        $this->assertSame('context.group_name', $catalog->suggest('GRUPO')['path']);
        $this->assertSame('resources.physical_materials', $catalog->suggest('FÍSICOS')['path']);
        $this->assertSame('resources.digital_resources', $catalog->suggest('DIGITALES')['path']);
    }

    /** @return list<array{id:string,kind:string,text_excerpt:string}> */
    private function dailyZones(): array
    {
        $first = [
            'FORMATO FLEXIBLE DE PLANEACIÓN DIDÁCTICA',
            'GRADO: SEGUNDO',
            'GRUPO: K',
            'MAESTRO: ALEJANDRA MACÍAS CRUZ',
            'TITULO DEL PROYECTO: CONOCIENDO MI CUERPO',
            'FECHA: LUNES 07 DE SEPTIEMBRE DEL 2026',
            'CAMPO FORMATIVO: DE LO HUMANO A LO COMUNITARIO',
            'EJE ARTICULADOR: INCLUSIÓN',
            'CONTENIDO: CONSTRUCCIÓN DE LA IDENTIDAD PERSONAL',
            'PDA: RECONOCE ALGUNOS RASGOS DE SU IDENTIDAD',
            'MATERIALES',
            'FÍSICOS: ESPEJOS, SILUETA GRANDE',
            'DIGITALES: YOUTUBE',
            'SECEUNCIA DIDACTICA',
            'ETAPA',
            'ACTIVIDADES',
            'INICIO',
            'ACTIVIDADES DE INICIO DEL DÍA UNO',
            'DESARROLLO',
            'ACTIVIDADES DE DESARROLLO DEL DÍA UNO',
            'CIERRE',
            'ACTIVIDADES DE CIERRE DEL DÍA UNO',
            'TAREA:',
            'PROPUESTA DE EVALUACIÓN',
            'EVIDENCIAS:',
            'INSTRUMENTO',
        ];
        $second = [
            'FORMATO FLEXIBLE DE PLANEACIÓN DIDÁCTICA',
            'GRADO: SEGUNDO',
            'GRUPO: K',
            'MAESTRO: ALEJANDRA MACÍAS CRUZ',
            'TITULO DEL PROYECTO: CONTAMOS CON EL CUERPO',
            'FECHA: MARTES 08 DE SEPTIEMBRE DEL 2026',
            'CAMPO FORMATIVO: SABERES Y PENSAMIENTO CIENTÍFICO',
            'EJE ARTICULADOR: PENSAMIENTO CRÍTICO',
            'CONTENIDO: LOS SABERES NUMÉRICOS',
            'PDA: USA NÚMEROS CON DISTINTOS PROPÓSITOS',
            'MATERIALES',
            'FÍSICOS: DADO CORPORAL, TARJETAS CON NÚMEROS',
            'DIGITALES: YOUTUBE',
            'SECEUNCIA DIDACTICA',
            'ETAPA',
            'ACTIVIDADES',
            'INICIO',
            'ACTIVIDADES DE INICIO DEL DÍA DOS',
            'DESARROLLO',
            'ACTIVIDADES DE DESARROLLO DEL DÍA DOS',
            'CIERRE',
            'ACTIVIDADES DE CIERRE DEL DÍA DOS',
            'TAREA:',
            'PROPUESTA DE EVALUACIÓN',
            'EVIDENCIAS:',
            'INSTRUMENTO: DIARIO',
        ];

        $zones = [];
        foreach ([...$first, ...$second] as $index => $text) {
            $zones[] = ['id' => 'c:' . $index, 'kind' => 'cell', 'text_excerpt' => $text];
        }

        return $zones;
    }

    /** @return list<array<string,mixed>> */
    private function dailyAnchors(): array
    {
        $anchors = [];
        foreach ([
            1 => ['Grado', 'curricular_alignment.grade.name'],
            2 => ['Grupo', 'context.group_name'],
            4 => ['Titulo del proyecto', 'planning.project_name'],
            5 => ['Fecha', 'planning.starts_on'],
            6 => ['Campo formativo', 'curricular_alignment.fields'],
            7 => ['Eje articulador', 'curricular_alignment.articulating_axes'],
            8 => ['Contenido', 'curricular_alignment.contents'],
            9 => ['PDA', 'curricular_alignment.pdas'],
            11 => ['Físicos', 'resources.physical_materials'],
            12 => ['Digitales', 'resources.digital_resources'],
            16 => ['Inicio', 'sessions.opening', 'c:17'],
            18 => ['Desarrollo', 'sessions.development', 'c:19'],
            20 => ['Cierre', 'sessions.closing', 'c:21'],
            24 => ['Evidencias', null, 'c:25'],
            27 => ['Grado', 'curricular_alignment.grade.name'],
            28 => ['Grupo', 'context.group_name'],
            30 => ['Titulo del proyecto', 'planning.project_name'],
            31 => ['Fecha', 'planning.starts_on'],
            32 => ['Campo formativo', 'curricular_alignment.fields'],
            33 => ['Eje articulador', 'curricular_alignment.articulating_axes'],
            34 => ['Contenido', 'curricular_alignment.contents'],
            35 => ['PDA', 'curricular_alignment.pdas'],
            37 => ['Físicos', 'resources.physical_materials'],
            38 => ['Digitales', 'resources.digital_resources'],
            42 => ['Inicio', 'sessions.opening', 'c:43'],
            44 => ['Desarrollo', 'sessions.development', 'c:45'],
            46 => ['Cierre', 'sessions.closing', 'c:47'],
            50 => ['Evidencias', null, 'c:51'],
            51 => ['Instrumento', null],
        ] as $id => $row) {
            $target = $row[2] ?? 'c:' . $id;
            $anchors[] = [
                'id' => 'c:' . $id,
                'target_id' => $target,
                'label' => $row[0],
                'suggested_path' => $row[1],
                'replacement_mode' => $target === 'c:' . $id ? 'replace_after_label' : 'replace_target',
            ];
        }

        return $anchors;
    }

    /** @return array<string,mixed> */
    private function session(string $date, string $title, string $field, string $content, string $pda, string $axis, string $opening, string $development, string $closing, string $evidence, string $instrument): array
    {
        return [
            'date' => $date,
            'title' => $title,
            'field_codes' => [$field],
            'content_codes' => [$content],
            'pda_codes' => [$pda],
            'axis_codes' => [$axis],
            'moments' => [
                ['type' => 'inicio', 'activities' => [['instruction' => $opening, 'materials' => ['YouTube']]]],
                ['type' => 'desarrollo', 'activities' => [['instruction' => $development, 'materials' => []]]],
                ['type' => 'cierre', 'activities' => [['instruction' => $closing, 'materials' => []]]],
            ],
            'formative_assessment' => [
                'criteria' => ['Participa y explica lo aprendido.'],
                'evidence' => [$evidence],
                'instrument_ids' => [$instrument],
                'feedback_strategy' => 'Retroalimentación oral.',
            ],
            'homework_or_extension' => null,
        ];
    }
}
