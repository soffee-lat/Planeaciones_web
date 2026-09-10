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

    public function test_admin_puede_abrir_recurso_de_formatos_institucionales(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get('/admin/institutional-formats')
            ->assertOk()
            ->assertSee('Formatos institucionales');
    }

    public function test_cliente_no_puede_abrir_recurso_administrativo(): void
    {
        $this->actingAs($this->customer())
            ->get('/admin/institutional-formats')
            ->assertForbidden();
    }

    public function test_crear_borrador_materializa_archivo_formato_y_version_privados(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $admin = $this->admin();
        $bytes = $this->institutionalTemplateBytes();

        $version = app(CreateInstitutionalFormatDraft::class)->execute(
            $owner,
            'Formato de mi escuela',
            'plantilla.docx',
            $bytes,
            $admin,
        );

        $this->assertSame($owner->id, $version->format->owner_id);
        $this->assertSame('pending_analysis', $version->format->status->value);
        $this->assertSame('institutional-v1', $version->renderer);
        $this->assertSame(hash('sha256', $bytes), $version->sourceFile->sha256);
        $this->assertTrue(Storage::disk('private')->exists($version->sourceFile->path));
    }

    public function test_carga_rechaza_docx_con_relacion_externa_antes_de_persistir(): void
    {
        Storage::fake('private');
        $owner = $this->customer();
        $admin = $this->admin();

        // Modificamos el XML dentro del paquete y lo reconstruimos para conservar
        // offsets/CRC válidos. Así probamos la regla de seguridad concreta y no
        // terminamos rechazando antes el archivo por ZIP corrupto.
        $package = OfficeOpenXmlPackage::fromBytes($this->institutionalTemplateBytes());
        $relationships = str_replace(
            'Target="word/document.xml"',
            'Target="https://example.test/document.xml" TargetMode="External"',
            $package->get('_rels/.rels'),
        );
        $package->replace('_rels/.rels', $relationships);
        $bytes = $package->toBytes();

        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_SOURCE_DOCX_EXTERNAL_RELATIONSHIP');

        try {
            app(CreateInstitutionalFormatDraft::class)->execute(
                $owner,
                'Formato inseguro',
                'plantilla.docx',
                $bytes,
                $admin,
            );
        } finally {
            $this->assertSame(0, InstitutionalFormat::query()->count());
        }
    }

    public function test_descarga_de_muestra_es_privada_y_exige_administrador(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $this->admin());
        $admin = $this->admin();

        $this->actingAs($admin)
            ->get(route('format-samples.download', [$sample, $sample->docx_file_id]))
            ->assertOk();

        $this->actingAs($owner)
            ->get(route('format-samples.download', [$sample, $sample->docx_file_id]))
            ->assertForbidden();
    }
}
