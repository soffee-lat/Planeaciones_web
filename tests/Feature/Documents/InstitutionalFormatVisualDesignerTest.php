<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class InstitutionalFormatVisualDesignerTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_propietario_puede_abrir_disenador_y_fuente_privada(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);

        $this->actingAs($owner)
            ->get(route('institutional-formats.designer', $version->format_id))
            ->assertOk()
            ->assertSee('Diseñador visual de formato')
            ->assertSee('Marca el contenido que cambia, no el nombre del campo')
            ->assertSee('Generar y ver ejemplo');

        $this->actingAs($owner)
            ->get(route('institutional-formats.source', $version->format_id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_otro_cliente_y_administrador_no_pueden_abrir_disenador_ni_fuente(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);

        foreach ([$this->customer(), $this->admin()] as $actor) {
            $this->actingAs($actor)
                ->get(route('institutional-formats.designer', $version->format_id))
                ->assertForbidden();

            $this->actingAs($actor)
                ->get(route('institutional-formats.source', $version->format_id))
                ->assertForbidden();
        }
    }

    public function test_zona_no_reconocida_puede_convertirse_en_campo_estandar(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);

        $zones = collect($version->validation_report['analysis']['document_zones']);
        $candidateIds = collect($version->validation_report['analysis']['anchors'])->pluck('id');
        $zone = $zones->first(fn (array $zone): bool => ! $candidateIds->contains($zone['id']) && ! $zone['is_blank']);
        $this->assertNotNull($zone);

        $response = $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'bind',
                'field_path' => 'planning.title',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertSame('planning.title', $response->json('mapping.anchors.' . str_replace('.', '\\.', $zone['id'])) ?? $version->fresh()->mapping['anchors'][$zone['id']]);
        $this->assertSame('planning.title', $version->fresh()->mapping['anchors'][$zone['id']]);
    }

    public function test_profesor_puede_crear_campo_personalizado_sobre_una_zona_visual(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);
        $zone = collect($version->validation_report['analysis']['document_zones'])->first();
        $this->assertNotNull($zone);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'custom',
                'custom_label' => 'Producto integrador',
                'custom_type' => 'long_text',
                'custom_instruction' => 'Describe el producto final del proyecto.',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $mapping = $version->fresh()->mapping;
        $key = array_key_first($mapping['custom_fields']);
        $this->assertNotNull($key);
        $path = 'custom.' . $key;
        $this->assertSame('Producto integrador', $mapping['custom_fields'][$key]['label']);
        $this->assertSame($path, $mapping['anchors'][$zone['id']]);
    }

    public function test_zona_visual_puede_marcarse_como_no_modificable_y_quitarse_despues(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);
        $zone = collect($version->validation_report['analysis']['document_zones'])->first();
        $this->assertNotNull($zone);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'ignore',
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $mapping = $version->fresh()->mapping;
        $this->assertContains($zone['id'], $mapping['ignored_zones']);
        $this->assertArrayNotHasKey($zone['id'], $mapping['anchors']);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'unbind',
            ])
            ->assertOk();

        $this->assertNotContains($zone['id'], $version->fresh()->mapping['ignored_zones']);
    }

    public function test_fragmento_fecha_reemplaza_solo_valor_y_conserva_etiqueta(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, [], false, ['Fecha: 11/09/2026']);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
        $zone = collect($version->validation_report['analysis']['document_zones'])
            ->first(fn (array $zone): bool => ($zone['text_excerpt'] ?? null) === 'Fecha: 11/09/2026');
        $this->assertNotNull($zone);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'bind',
                'field_path' => 'planning.starts_on',
                'fragment_start' => 7,
                'fragment_end' => 17,
                'fragment_text' => '11/09/2026',
                'label_hint' => 'Fecha',
            ])
            ->assertOk();

        $fresh = $version->fresh();
        $this->assertCount(1, $fresh->mapping['fragments']);
        $this->assertArrayNotHasKey($zone['id'], $fresh->mapping['anchors']);

        $sample = app(RenderInstitutionalFormatSample::class)->execute($fresh, $owner);
        $bytes = Storage::disk('private')->get($sample->docxFile->path);
        $this->assertStringContainsString('Fecha:', $bytes);
        $this->assertStringNotContainsString('11/09/2026', $bytes);
        $this->assertStringContainsString('MUESTRA', $bytes);
    }

    public function test_dos_fragmentos_pueden_convivir_en_una_misma_linea(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $line = 'GRADO: 3°  GRUPO: A';
        $draft = $this->institutionalDraft($owner->id, [], false, [$line]);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
        $zone = collect($version->validation_report['analysis']['document_zones'])
            ->first(fn (array $zone): bool => ($zone['text_excerpt'] ?? null) === $line);
        $this->assertNotNull($zone);

        $this->actingAs($owner)->postJson(route('institutional-formats.visual-binding', $version->format_id), [
            'zone_id' => $zone['id'],
            'mode' => 'bind',
            'field_path' => 'curricular_alignment.grade.name',
            'fragment_start' => 7,
            'fragment_end' => 9,
            'fragment_text' => '3°',
            'label_hint' => 'GRADO',
        ])->assertOk();

        $this->actingAs($owner)->postJson(route('institutional-formats.visual-binding', $version->format_id), [
            'zone_id' => $zone['id'],
            'mode' => 'custom',
            'fragment_start' => 18,
            'fragment_end' => 19,
            'fragment_text' => 'A',
            'label_hint' => 'GRUPO',
            'custom_label' => 'Grupo',
            'custom_type' => 'text',
        ])->assertOk();

        $fresh = $version->fresh();
        $this->assertCount(2, $fresh->mapping['fragments']);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($fresh, $owner);
        $bytes = Storage::disk('private')->get($sample->docxFile->path);
        $this->assertStringContainsString('GRADO:', $bytes);
        $this->assertStringContainsString('GRUPO:', $bytes);
        $this->assertStringNotContainsString('GRADO: 3°', $bytes);
        $this->assertStringNotContainsString('GRUPO: A', $bytes);
    }

    public function test_binding_preciso_sobrevive_reanalisis_y_evata_reemplazo_de_zona_completa(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, [], false, ['Fecha: 11/09/2026']);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
        $zone = collect($version->validation_report['analysis']['document_zones'])
            ->first(fn (array $zone): bool => ($zone['text_excerpt'] ?? null) === 'Fecha: 11/09/2026');
        $this->assertNotNull($zone);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'bind',
                'field_path' => 'planning.starts_on',
                'fragment_start' => 7,
                'fragment_end' => 17,
                'fragment_text' => '11/09/2026',
                'label_hint' => 'Fecha',
            ])
            ->assertOk();

        $reanalyzed = app(AnalyzeInstitutionalFormatVersion::class)->execute($version->fresh(), $owner);
        $this->assertCount(1, $reanalyzed->mapping['fragments']);

        foreach ($reanalyzed->validation_report['analysis']['anchors'] as $anchor) {
            if (is_array($anchor) && ($anchor['target_id'] ?? null) === $zone['id']) {
                $this->assertArrayNotHasKey((string) $anchor['id'], $reanalyzed->mapping['anchors']);
            }
        }

        $sample = app(RenderInstitutionalFormatSample::class)->execute($reanalyzed, $owner);
        $bytes = Storage::disk('private')->get($sample->docxFile->path);
        $this->assertStringContainsString('Fecha:', $bytes);
        $this->assertStringNotContainsString('11/09/2026', $bytes);
    }

    public function test_renderer_puede_usar_campo_manual_en_zona_que_heuristica_no_reconocio(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, [], false, ['Título de la planeación:', 'Texto institucional fijo']);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);

        $zone = collect($version->validation_report['analysis']['document_zones'])
            ->first(fn (array $zone): bool => ($zone['text_excerpt'] ?? null) === 'Texto institucional fijo');
        $this->assertNotNull($zone);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'custom',
                'custom_label' => 'Producto final',
                'custom_type' => 'long_text',
            ])
            ->assertOk();

        $sample = app(RenderInstitutionalFormatSample::class)->execute($version->fresh(), $owner);
        $bytes = Storage::disk('private')->get($sample->docxFile->path);
        $this->assertStringNotContainsString('Texto institucional fijo', $bytes);
        $this->assertStringContainsString('MUESTRA', $bytes);
        $this->assertStringContainsString('Producto final', $bytes);
    }

    public function test_disenador_genera_muestra_con_configuracion_visual_actual(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);

        $response = $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-preview', $version->format_id))
            ->assertOk()
            ->assertJsonPath('ok', true);

        $this->assertNotEmpty($response->json('pdf_url'));
        $this->assertNotEmpty($response->json('docx_url'));
        $this->assertSame('sample_ready', $version->fresh()->validation_report['status']);
    }
}
