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
            ->assertSee('Selecciona directamente una zona del documento')
            ->assertSee('Generar y ver ejemplo');

        $this->actingAs($owner)
            ->get(route('institutional-formats.source', $version->format_id))
            ->assertOk()
            ->assertHeader('Content-Type', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    }

    public function test_otro_cliente_no_puede_abrir_disenador_ni_fuente(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);
        $other = $this->customer();

        $this->actingAs($other)
            ->get(route('institutional-formats.designer', $version->format_id))
            ->assertForbidden();

        $this->actingAs($other)
            ->get(route('institutional-formats.source', $version->format_id))
            ->assertForbidden();
    }

    public function test_zona_no_reconocida_puede_convertirse_en_campo_estandar(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);

        $zones = collect($version->validation_report['analysis']['document_zones']);
        $candidateIds = collect($version->validation_report['analysis']['anchors'])->pluck('id');
        $zone = $zones->first(fn (array $zone): bool => ! $candidateIds->contains($zone['id']) && ! $zone['is_blank']);
        $this->assertNotNull($zone);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'bind',
                'field_path' => 'planning.title',
            ])
            ->assertOk()
            ->assertJsonPath('field_path', 'planning.title');

        $this->assertSame('planning.title', $version->fresh()->mapping['anchors'][$zone['id']]);
    }

    public function test_profesor_puede_crear_campo_personalizado_sobre_una_zona_visual(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);
        $zone = collect($version->validation_report['analysis']['document_zones'])->first();
        $this->assertNotNull($zone);

        $response = $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'custom',
                'custom_label' => 'Producto integrador',
                'custom_type' => 'long_text',
                'custom_instruction' => 'Describe el producto final del proyecto.',
            ])
            ->assertOk();

        $path = (string) $response->json('field_path');
        $this->assertStringStartsWith('custom.', $path);
        $key = substr($path, strlen('custom.'));
        $mapping = $version->fresh()->mapping;
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
            ->assertJsonPath('ignored', true);

        $mapping = $version->fresh()->mapping;
        $this->assertContains($zone['id'], $mapping['ignored_zones']);
        $this->assertArrayNotHasKey($zone['id'], $mapping['anchors']);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.visual-binding', $version->format_id), [
                'zone_id' => $zone['id'],
                'mode' => 'unbind',
            ])
            ->assertOk()
            ->assertJsonPath('ignored', false);

        $this->assertNotContains($zone['id'], $version->fresh()->mapping['ignored_zones']);
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
