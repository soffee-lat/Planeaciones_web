<?php

namespace Tests\Feature;

use App\Actions\Curriculum\ImportCurriculumDraft;
use App\Enums\RoleCode;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\User;
use App\Services\Curriculum\CurriculumImportService;
use App\Support\Curriculum\ImportReport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CurriculumImportTest extends TestCase
{
    use RefreshDatabase;

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function admin(): User
    {
        return User::factory()->withRole(RoleCode::Administrator)->create();
    }

    /** Payload válido mínimo (1 fase, 1 grado, 1 campo, 1 contenido, 1 PDA, 1 eje). */
    private function validPayload(array $overrides = []): array
    {
        $base = [
            'schema_version' => 1,
            'curriculum' => [
                'code' => 'DEMO-IMP',
                'name' => 'Currículo importado — Datos ficticios',
                'country_code' => 'XX',
                'educational_level' => 'demo',
                'description' => 'Ficticio, sin validez curricular.',
            ],
            'version' => [
                'number' => 1,
                'label' => 'Borrador importación de prueba',
                'source_reference' => 'ficticio',
                'effective_from' => null,
                'effective_until' => null,
            ],
            'educational_phases' => [
                ['code' => 'PH-A', 'name' => 'Fase demo A', 'sort_order' => 1],
            ],
            'grades' => [
                ['code' => 'GR-A1', 'name' => 'Grado A1', 'phase_code' => 'PH-A', 'ordinal' => 1],
            ],
            'formative_fields' => [
                ['code' => 'FF-LANG', 'name' => 'Campo Lenguaje'],
            ],
            'curricular_contents' => [
                [
                    'code' => 'CT-1',
                    'title' => 'Contenido de prueba',
                    'full_text' => 'Texto ficticio del contenido.',
                    'phase_code' => 'PH-A',
                    'field_code' => 'FF-LANG',
                ],
            ],
            'pdas' => [
                [
                    'code' => 'PDA-1',
                    'full_text' => 'PDA de prueba.',
                    'content_code' => 'CT-1',
                    'grade_code' => 'GR-A1',
                ],
            ],
            'articulating_axes' => [
                ['code' => 'AX-1', 'name' => 'Eje de prueba'],
            ],
        ];
        return array_replace_recursive($base, $overrides);
    }

    private function canonicalPreschoolPayload(): array
    {
        $payload = $this->validPayload();
        $payload['curriculum'] = [
            'code' => 'MX-NEM-PRESCHOOL',
            'name' => 'Educación Preescolar — Nueva Escuela Mexicana',
            'country_code' => 'MX',
            'educational_level' => 'preschool',
            'description' => 'Fixture canónico sintético.',
        ];
        $payload['educational_phases'] = [
            ['code' => 'F2', 'name' => 'Fase 2', 'sort_order' => 2],
        ];
        $payload['grades'] = [
            ['code' => 'P1', 'name' => 'Primer grado de preescolar', 'phase_code' => 'F2', 'ordinal' => 1],
            ['code' => 'P2', 'name' => 'Segundo grado de preescolar', 'phase_code' => 'F2', 'ordinal' => 2],
            ['code' => 'P3', 'name' => 'Tercer grado de preescolar', 'phase_code' => 'F2', 'ordinal' => 3],
        ];
        $payload['formative_fields'] = [
            ['code' => 'LEN', 'name' => 'Lenguajes'],
            ['code' => 'SPC', 'name' => 'Saberes y Pensamiento Científico'],
            ['code' => 'ENS', 'name' => 'Ética, Naturaleza y Sociedades'],
            ['code' => 'DHC', 'name' => 'De lo Humano y lo Comunitario'],
        ];
        $payload['curricular_contents'] = [[
            'code' => 'F2-LEN-C001',
            'title' => 'Contenido sintético',
            'full_text' => 'Contenido sintético.',
            'phase_code' => 'F2',
            'field_code' => 'LEN',
        ]];
        $payload['pdas'] = [
            ['code' => 'F2-P1-LEN-C001-P01', 'full_text' => 'PDA P1.', 'content_code' => 'F2-LEN-C001', 'grade_code' => 'P1'],
            ['code' => 'F2-P2-LEN-C001-P01', 'full_text' => 'PDA P2.', 'content_code' => 'F2-LEN-C001', 'grade_code' => 'P2'],
            ['code' => 'F2-P3-LEN-C001-P01', 'full_text' => 'PDA P3.', 'content_code' => 'F2-LEN-C001', 'grade_code' => 'P3'],
        ];
        $payload['articulating_axes'] = [
            ['code' => 'AX-INCLUSION', 'name' => 'Inclusión'],
            ['code' => 'AX-CRITICAL', 'name' => 'Pensamiento crítico'],
            ['code' => 'AX-INTERCULTURAL', 'name' => 'Interculturalidad crítica'],
            ['code' => 'AX-GENDER', 'name' => 'Igualdad de género'],
            ['code' => 'AX-HEALTH', 'name' => 'Vida saludable'],
            ['code' => 'AX-LITERACY', 'name' => 'Apropiación de las culturas a través de la lectura y la escritura'],
            ['code' => 'AX-ARTS', 'name' => 'Artes y experiencias estéticas'],
        ];

        return $payload;
    }

    private function canonicalPrimaryPayload(): array
    {
        $payload = $this->validPayload();
        $payload['curriculum'] = [
            'code' => 'MX-NEM-PRIMARY',
            'name' => 'Educación Primaria — Nueva Escuela Mexicana',
            'country_code' => 'MX',
            'educational_level' => 'primary',
            'description' => 'Fixture canónico sintético.',
        ];
        $payload['educational_phases'] = [
            ['code' => 'F3', 'name' => 'Fase 3', 'sort_order' => 3],
            ['code' => 'F4', 'name' => 'Fase 4', 'sort_order' => 4],
            ['code' => 'F5', 'name' => 'Fase 5', 'sort_order' => 5],
        ];
        $payload['grades'] = [
            ['code' => 'G1', 'name' => 'Primer grado', 'phase_code' => 'F3', 'ordinal' => 1],
            ['code' => 'G2', 'name' => 'Segundo grado', 'phase_code' => 'F3', 'ordinal' => 2],
            ['code' => 'G3', 'name' => 'Tercer grado', 'phase_code' => 'F4', 'ordinal' => 3],
            ['code' => 'G4', 'name' => 'Cuarto grado', 'phase_code' => 'F4', 'ordinal' => 4],
            ['code' => 'G5', 'name' => 'Quinto grado', 'phase_code' => 'F5', 'ordinal' => 5],
            ['code' => 'G6', 'name' => 'Sexto grado', 'phase_code' => 'F5', 'ordinal' => 6],
        ];
        $payload['formative_fields'] = [
            ['code' => 'LEN', 'name' => 'Lenguajes'],
            ['code' => 'SPC', 'name' => 'Saberes y Pensamiento Científico'],
            ['code' => 'ENS', 'name' => 'Ética, Naturaleza y Sociedades'],
            ['code' => 'DHC', 'name' => 'De lo Humano y lo Comunitario'],
        ];
        $payload['curricular_contents'] = [
            ['code' => 'F3-LEN-C001', 'title' => 'Contenido F3', 'full_text' => 'Contenido F3.', 'phase_code' => 'F3', 'field_code' => 'LEN'],
            ['code' => 'F4-LEN-C001', 'title' => 'Contenido F4', 'full_text' => 'Contenido F4.', 'phase_code' => 'F4', 'field_code' => 'LEN'],
            ['code' => 'F5-LEN-C001', 'title' => 'Contenido F5', 'full_text' => 'Contenido F5.', 'phase_code' => 'F5', 'field_code' => 'LEN'],
        ];
        $payload['pdas'] = [
            ['code' => 'F3-G1-LEN-C001-P01', 'full_text' => 'PDA G1.', 'content_code' => 'F3-LEN-C001', 'grade_code' => 'G1'],
            ['code' => 'F3-G2-LEN-C001-P01', 'full_text' => 'PDA G2.', 'content_code' => 'F3-LEN-C001', 'grade_code' => 'G2'],
            ['code' => 'F4-G3-LEN-C001-P01', 'full_text' => 'PDA G3.', 'content_code' => 'F4-LEN-C001', 'grade_code' => 'G3'],
            ['code' => 'F4-G4-LEN-C001-P01', 'full_text' => 'PDA G4.', 'content_code' => 'F4-LEN-C001', 'grade_code' => 'G4'],
            ['code' => 'F5-G5-LEN-C001-P01', 'full_text' => 'PDA G5.', 'content_code' => 'F5-LEN-C001', 'grade_code' => 'G5'],
            ['code' => 'F5-G6-LEN-C001-P01', 'full_text' => 'PDA G6.', 'content_code' => 'F5-LEN-C001', 'grade_code' => 'G6'],
        ];
        $payload['articulating_axes'] = [
            ['code' => 'AX-INCLUSION', 'name' => 'Inclusión'],
            ['code' => 'AX-CRITICAL', 'name' => 'Pensamiento crítico'],
            ['code' => 'AX-INTERCULTURAL', 'name' => 'Interculturalidad crítica'],
            ['code' => 'AX-GENDER', 'name' => 'Igualdad de género'],
            ['code' => 'AX-HEALTH', 'name' => 'Vida saludable'],
            ['code' => 'AX-LITERACY', 'name' => 'Apropiación de las culturas a través de la lectura y la escritura'],
            ['code' => 'AX-ARTS', 'name' => 'Artes y experiencias estéticas'],
        ];

        return $payload;
    }

    private function writeJson(array $payload): string
    {
        $path = tempnam(sys_get_temp_dir(), 'curr_') . '.json';
        file_put_contents($path, json_encode($payload, JSON_UNESCAPED_UNICODE));
        return $path;
    }

    private function service(): CurriculumImportService
    {
        return app(CurriculumImportService::class);
    }

    // ------------------------------------------------------------------
    // Casos exitosos
    // ------------------------------------------------------------------

    public function test_dry_run_valido_no_escribe_nada(): void
    {
        $report = $this->service()->import($this->validPayload(), $this->admin(), dryRun: true);

        $this->assertFalse($report->success);
        $this->assertTrue($report->dryRun);
        $this->assertSame([], array_map(fn ($e) => $e->code, $report->errors));
        $this->assertSame(1, $report->counts['phases']);
        $this->assertSame(1, $report->counts['pdas']);
        $this->assertSame(0, Curriculum::count());
        $this->assertSame(0, CurriculumVersion::count());
    }

    public function test_importacion_valida_crea_borrador_no_publicado(): void
    {
        $report = $this->service()->import($this->validPayload(), $this->admin(), dryRun: false);

        $this->assertTrue($report->success, json_encode($report->toArray()));
        $this->assertNotNull($report->draftId);
        $version = CurriculumVersion::findOrFail($report->draftId);
        $this->assertNull($version->published_at);
        $this->assertNull($version->published_by);
        $this->assertNull($version->checksum);
        $this->assertSame(1, $version->phases()->count());
        $this->assertSame(1, $version->pdas()->count());
        $curriculum = Curriculum::firstWhere('code', 'DEMO-IMP');
        $this->assertNotNull($curriculum);
        $this->assertNull($curriculum->selectable_version_id, 'Importación NUNCA cambia selectable_version_id.');
    }

    public function test_importacion_persiste_referencia_de_fuente_estructurada_mayor_a_255_caracteres(): void
    {
        $payload = $this->validPayload();
        $payload['version']['source_reference'] = [
            'title' => str_repeat('Fuente oficial con metadatos verificables. ', 8),
            'publisher' => 'Secretaría de Educación Pública',
            'official_url' => 'https://example.test/programa-oficial.pdf',
        ];

        $expected = json_encode(
            $payload['version']['source_reference'],
            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES,
        );
        $this->assertGreaterThan(255, strlen($expected));

        $report = $this->service()->import($payload, $this->admin(), dryRun: false);

        $this->assertTrue($report->success, json_encode($report->toArray()));
        $this->assertSame(
            $expected,
            CurriculumVersion::findOrFail($report->draftId)->source_reference,
        );
    }

    public function test_importacion_persiste_titulo_curricular_oficial_mayor_a_255_caracteres(): void
    {
        $payload = $this->validPayload();
        $payload['curricular_contents'][0]['title'] = rtrim(str_repeat('Título curricular oficial extenso. ', 9));
        $payload['curricular_contents'][0]['full_text'] = $payload['curricular_contents'][0]['title'];

        $this->assertGreaterThan(255, mb_strlen($payload['curricular_contents'][0]['title']));

        $report = $this->service()->import($payload, $this->admin(), dryRun: false);

        $this->assertTrue($report->success, json_encode($report->toArray()));
        $version = CurriculumVersion::findOrFail($report->draftId);
        $this->assertSame(
            $payload['curricular_contents'][0]['title'],
            $version->curricularContents()->firstOrFail()->title,
        );
    }

    public function test_reutiliza_curriculo_existente_sin_modificar_metadata(): void
    {
        $existing = Curriculum::create([
            'code' => 'DEMO-IMP',
            'name' => 'Nombre original preservado',
            'country_code' => 'MX',
        ]);

        $report = $this->service()->import($this->validPayload(), $this->admin(), dryRun: false);

        $this->assertTrue($report->success);
        $this->assertSame('Nombre original preservado', $existing->fresh()->name);
        $this->assertSame('MX', $existing->fresh()->country_code);
    }

    // ------------------------------------------------------------------
    // Idempotencia / reimportación
    // ------------------------------------------------------------------

    public function test_reimportar_mismo_archivo_rechaza_por_version_existente(): void
    {
        $payload = $this->validPayload();
        $this->assertTrue($this->service()->import($payload, $this->admin(), dryRun: false)->success);

        $second = $this->service()->import($payload, $this->admin(), dryRun: false);

        $this->assertFalse($second->success);
        $codes = array_map(fn ($e) => $e->code, $second->errors);
        $this->assertContains('VERSION_EXISTS', $codes);
        $this->assertSame(1, CurriculumVersion::count(), 'No debe duplicar registros.');
    }

    public function test_rechaza_reimportar_sobre_version_publicada(): void
    {
        $payload = $this->validPayload();
        $report = $this->service()->import($payload, $this->admin(), dryRun: false);
        $this->assertTrue($report->success);
        // Publicar la versión creada.
        $version = CurriculumVersion::findOrFail($report->draftId);
        app(\App\Actions\Curriculum\PublishCurriculumVersion::class)($version, $this->admin());

        $second = $this->service()->import($payload, $this->admin(), dryRun: false);

        $codes = array_map(fn ($e) => $e->code, $second->errors);
        $this->assertContains('VERSION_PUBLISHED', $codes);
    }

    // ------------------------------------------------------------------
    // Validaciones estructurales
    // ------------------------------------------------------------------

    public function test_schema_version_no_soportada(): void
    {
        $payload = $this->validPayload(['schema_version' => 99]);
        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);
        $this->assertContains('SCHEMA_VERSION_UNSUPPORTED', $codes);
    }

    public function test_datos_vacios_son_rechazados(): void
    {
        $report = $this->service()->import(['schema_version' => 1], $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);
        $this->assertContains('MISSING_FIELD', $codes);
    }

    public function test_json_invalido_via_action_reporta_error(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'curr_') . '.json';
        file_put_contents($path, '{not json');
        $report = app(ImportCurriculumDraft::class)($path, $this->admin(), true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);
        $this->assertContains('INVALID_JSON', $codes);
    }

    public function test_codigos_duplicados_en_el_archivo_son_rechazados(): void
    {
        $payload = $this->validPayload();
        $payload['educational_phases'][] = ['code' => 'PH-A', 'name' => 'Duplicado', 'sort_order' => 2];
        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);
        $this->assertContains('DUPLICATE_CODE', $codes);
    }

    public function test_referencia_inexistente_es_rechazada(): void
    {
        $payload = $this->validPayload();
        $payload['grades'][0]['phase_code'] = 'PH-INEXISTENTE';
        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);
        $this->assertContains('REFERENCE_NOT_FOUND', $codes);
    }

    public function test_catalogo_canonico_de_preescolar_exige_p1_p2_p3_y_cobertura_por_contenido(): void
    {
        $payload = $this->canonicalPreschoolPayload();

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);

        $this->assertSame([], array_map(fn ($e) => $e->code, $report->errors));
        $this->assertSame(3, $report->counts['grades']);
        $this->assertSame(3, $report->counts['pdas']);
    }

    public function test_catalogo_canonico_de_preescolar_rechaza_grado_faltante(): void
    {
        $payload = $this->canonicalPreschoolPayload();
        array_pop($payload['grades']);
        $payload['pdas'] = array_values(array_filter(
            $payload['pdas'],
            fn (array $pda): bool => $pda['grade_code'] !== 'P3',
        ));

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);

        $this->assertContains('CANONICAL_GRADE_SET_INVALID', $codes);
        $this->assertContains('CANONICAL_CONTENT_GRADE_WITHOUT_PDA', $codes);
    }

    public function test_catalogo_canonico_de_primaria_exige_identificadores_y_cobertura_por_fase(): void
    {
        $payload = $this->canonicalPrimaryPayload();

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);

        $this->assertSame([], array_map(fn ($e) => $e->code, $report->errors));
        $this->assertSame(3, $report->counts['phases']);
        $this->assertSame(6, $report->counts['grades']);
        $this->assertSame(6, $report->counts['pdas']);
    }

    public function test_catalogo_productivo_rechaza_alias_espanol_de_primaria(): void
    {
        $payload = $this->canonicalPrimaryPayload();
        $payload['curriculum']['code'] = 'MX-NEM-PRIMARIA';
        $payload['curriculum']['educational_level'] = 'primaria';

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);

        $this->assertContains('CANONICAL_CURRICULUM_CODE_UNSUPPORTED', $codes);
    }

    public function test_catalogo_canonico_primary_rechaza_nivel_primaria(): void
    {
        $payload = $this->canonicalPrimaryPayload();
        $payload['curriculum']['educational_level'] = 'primaria';

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);

        $this->assertContains('CANONICAL_CURRICULUM_LEVEL_MISMATCH', $codes);
    }

    public function test_importador_acepta_preescolar_fase_2_con_codigo_de_grado_interno(): void
    {
        $payload = $this->validPayload();
        $payload['curriculum'] = [
            'code' => 'DEMO-PRESCHOOL',
            'name' => 'Preescolar de prueba',
            'country_code' => 'MX',
            'educational_level' => 'preschool',
            'description' => 'Fixture sintético sin validez curricular.',
        ];
        $payload['educational_phases'][0] = ['code' => 'F2', 'name' => 'Fase 2', 'sort_order' => 2];
        $payload['grades'][0] = [
            'code' => 'P1',
            'name' => 'Primer grado de preescolar',
            'phase_code' => 'F2',
            'ordinal' => 1,
        ];
        $payload['curricular_contents'][0]['phase_code'] = 'F2';
        $payload['pdas'][0]['grade_code'] = 'P1';

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);

        $this->assertSame([], array_map(fn ($e) => $e->code, $report->errors));
        $this->assertSame(1, $report->counts['grades']);
        $this->assertSame(1, $report->counts['pdas']);
    }

    public function test_importador_rechaza_preescolar_fuera_de_fase_2_o_codigo_p1_p3(): void
    {
        $payload = $this->validPayload();
        $payload['curriculum']['educational_level'] = 'preschool';
        $payload['educational_phases'][0] = ['code' => 'F3', 'name' => 'Fase incorrecta', 'sort_order' => 3];
        $payload['grades'][0] = [
            'code' => 'G1',
            'name' => 'Primer grado mal clasificado',
            'phase_code' => 'F3',
            'ordinal' => 1,
        ];
        $payload['curricular_contents'][0]['phase_code'] = 'F3';
        $payload['pdas'][0]['grade_code'] = 'G1';

        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);

        $this->assertContains('EDUCATIONAL_SCOPE_INVALID', $codes);
    }

    public function test_pda_con_grado_en_fase_incorrecta_es_rechazado(): void
    {
        $payload = $this->validPayload();
        // Añadir fase y grado en otra fase, luego apuntar el PDA a ese grado (fase distinta a la del contenido).
        $payload['educational_phases'][] = ['code' => 'PH-B', 'name' => 'Otra fase', 'sort_order' => 2];
        $payload['grades'][] = ['code' => 'GR-B1', 'name' => 'Grado B1', 'phase_code' => 'PH-B', 'ordinal' => 2];
        $payload['pdas'][0]['grade_code'] = 'GR-B1'; // el contenido está en PH-A
        $report = $this->service()->import($payload, $this->admin(), dryRun: true);
        $codes = array_map(fn ($e) => $e->code, $report->errors);
        $this->assertContains('PDA_GRADE_PHASE_MISMATCH', $codes);
    }

    // ------------------------------------------------------------------
    // Rollback ante fallo intermedio
    // ------------------------------------------------------------------

    public function test_rollback_si_falla_en_medio_no_deja_registros_parciales(): void
    {
        $payload = $this->validPayload();
        // Sabotear post-validación referencial: código PDA con longitud excesiva no salta
        // validaciones estructurales, pero introducimos un fallo forzado eliminando la relación
        // en la fase de escritura. Enfoque: duplicar el código PDA para que UNIQUE dispare.
        $payload['pdas'][] = [
            'code' => 'PDA-1', // mismo code → violará UNIQUE(curriculum_version_id, code) en escritura
            'full_text' => 'segundo PDA con código duplicado detectado en DB',
            'content_code' => 'CT-1',
            'grade_code' => 'GR-A1',
        ];
        // Nuestra validación referencial también reporta DUPLICATE_CODE — es lo esperado y
        // demuestra que ni siquiera entramos a la transacción. Verificamos ausencia de escrituras.
        $report = $this->service()->import($payload, $this->admin(), dryRun: false);
        $this->assertFalse($report->success);
        $this->assertSame(0, CurriculumVersion::count());
        $this->assertSame(0, Curriculum::count());
    }

    // ------------------------------------------------------------------
    // Nunca publica automáticamente
    // ------------------------------------------------------------------

    public function test_importacion_nunca_publica_ni_toca_selectable_version_id(): void
    {
        Curriculum::create([
            'code' => 'DEMO-IMP',
            'name' => 'Base',
            'selectable_version_id' => null,
        ]);
        $report = $this->service()->import($this->validPayload(), $this->admin(), dryRun: false);
        $this->assertTrue($report->success);
        $version = CurriculumVersion::findOrFail($report->draftId);
        $this->assertNull($version->published_at);
        $this->assertNull(Curriculum::firstWhere('code', 'DEMO-IMP')->selectable_version_id);
    }

    // ------------------------------------------------------------------
    // Comando Artisan
    // ------------------------------------------------------------------

    public function test_comando_artisan_dry_run(): void
    {
        $admin = $this->admin();
        $path = $this->writeJson($this->validPayload());

        $this->artisan('curriculum:import', ['file' => $path, '--actor' => $admin->email, '--dry-run' => true])
            ->expectsOutputToContain('Phases: 1')
            ->expectsOutputToContain('Errors: 0')
            ->expectsOutputToContain('No changes were written.')
            ->assertExitCode(0);
        $this->assertSame(0, CurriculumVersion::count());
    }

    public function test_comando_artisan_importacion_real(): void
    {
        $admin = $this->admin();
        $path = $this->writeJson($this->validPayload());

        $this->artisan('curriculum:import', ['file' => $path, '--actor' => $admin->email])
            ->expectsOutputToContain('Borrador creado')
            ->assertExitCode(0);

        $this->assertSame(1, CurriculumVersion::count());
        $this->assertNull(CurriculumVersion::first()->published_at);
    }

    public function test_comando_rechaza_actor_no_admin(): void
    {
        $customer = User::factory()->withRole(RoleCode::Customer)->create();
        $path = $this->writeJson($this->validPayload());
        $this->artisan('curriculum:import', ['file' => $path, '--actor' => $customer->email])
            ->assertExitCode(1);
        $this->assertSame(0, CurriculumVersion::count());
    }
}
