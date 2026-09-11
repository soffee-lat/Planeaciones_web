<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\CreateInstitutionalFormatDraft;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Exceptions\DocumentFormatException;
use App\Models\InstitutionalFormat;
use App\Services\Documents\OfficeOpenXmlPackage;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class InstitutionalFormatAdminTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_cliente_puede_abrir_sus_formatos_y_admin_no_tiene_recurso_de_gestion(): void
    {
        $this->actingAs($this->customer())->get('/app/institutional-formats')->assertOk()->assertSee('Mis formatos');
        $this->actingAs($this->admin())->get('/admin/institutional-formats')->assertNotFound();
    }

    public function test_detalle_explica_deteccion_ejemplo_y_activacion_en_lenguaje_de_docente(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        app(RenderInstitutionalFormatSample::class)->execute($version, $owner);

        $this->actingAs($owner)
            ->get('/app/institutional-formats/' . $version->format_id)
            ->assertOk()
            ->assertSee('Esto fue lo que entendimos')
            ->assertSee('Mira un ejemplo antes de decidir')
            ->assertSee('Cuando el ejemplo se vea bien')
            ->assertSee('Campos encontrados')
            ->assertSee('Qué hacer ahora');
    }

    public function test_cliente_crea_su_propio_borrador_privado(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $bytes = $this->institutionalTemplateBytes();
        $version = app(CreateInstitutionalFormatDraft::class)->execute($owner, 'Formato de mi escuela', 'plantilla.docx', $bytes, $owner);
        $this->assertSame($owner->id, $version->format->owner_id);
        $this->assertSame($owner->id, $version->sourceFile->uploaded_by);
        $this->assertSame('pending_analysis', $version->format->status->value);
        $this->assertSame(2, $version->schema_version);
        $this->assertTrue(Storage::disk('private')->exists($version->sourceFile->path));
    }

    public function test_cliente_no_puede_crear_formato_para_otro_propietario(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_OWNER_REQUIRED');
        app(CreateInstitutionalFormatDraft::class)->execute($owner, 'Ajeno', 'plantilla.docx', $this->institutionalTemplateBytes(), $other);
    }

    public function test_carga_rechaza_docx_con_relacion_externa_antes_de_persistir(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $package = OfficeOpenXmlPackage::fromBytes($this->institutionalTemplateBytes());
        $relationships = str_replace('Target="word/document.xml"', 'Target="https://example.test/document.xml" TargetMode="External"', $package->get('_rels/.rels'));
        $package->replace('_rels/.rels', $relationships);
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_SOURCE_DOCX_EXTERNAL_RELATIONSHIP');
        try {
            app(CreateInstitutionalFormatDraft::class)->execute($owner, 'Formato inseguro', 'plantilla.docx', $package->toBytes(), $owner);
        } finally {
            $this->assertSame(0, InstitutionalFormat::query()->count());
        }
    }

    public function test_descarga_de_muestra_es_privada_y_solo_para_propietario(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        $this->actingAs($owner)->get(route('format-samples.download', [$sample, $sample->docx_file_id]))->assertOk();
        $this->actingAs($this->customer())->get(route('format-samples.download', [$sample, $sample->docx_file_id]))->assertForbidden();
        $this->actingAs($this->admin())->get(route('format-samples.download', [$sample, $sample->docx_file_id]))->assertForbidden();
    }
}
