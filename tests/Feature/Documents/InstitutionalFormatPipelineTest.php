<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\ConfigureInstitutionalFormatMapping;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Enums\FileCategory;
use App\Enums\FormatSampleStatus;
use App\Enums\InstitutionalFormatStatus;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersionSample;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class InstitutionalFormatPipelineTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_analisis_detecta_placeholders_y_deja_formato_configurable(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, ['TITLE', 'TOPIC'], true);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $this->admin());
        $this->assertSame('analysis_complete', $version->validation_report['status']);
        $this->assertSame(['TITLE', 'TOPIC'], $version->validation_report['analysis']['placeholders']);
        $this->assertTrue($version->validation_report['analysis']['has_tables']);
        $this->assertContains('tables_present_review_sample_visually', $version->validation_report['analysis']['warnings']);
        $this->assertSame(InstitutionalFormatStatus::Configuring, $version->format->status);
    }

    public function test_fuente_sin_placeholders_se_marca_unsupported(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, []);
        try {
            app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $this->admin());
            $this->fail('Se esperaba FORMAT_SOURCE_PLACEHOLDERS_REQUIRED.');
        } catch (DocumentFormatException $e) {
            $this->assertSame('FORMAT_SOURCE_PLACEHOLDERS_REQUIRED', $e->getMessage());
        }
        $this->assertSame(InstitutionalFormatStatus::Unsupported, $draft['format']->fresh()->status);
        $this->assertSame('unsupported', $draft['version']->fresh()->validation_report['status']);
    }

    public function test_mapping_exige_cobertura_exacta_y_rutas_canonicas(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);
        try {
            app(ConfigureInstitutionalFormatMapping::class)->execute($version, [
                'schema_version' => 1,
                'placeholders' => ['TITLE' => 'planning.title'],
            ], $this->admin());
            $this->fail('Se esperaba FORMAT_MAPPING_PLACEHOLDER_MISMATCH.');
        } catch (DocumentFormatException $e) {
            $this->assertSame('FORMAT_MAPPING_PLACEHOLDER_MISMATCH', $e->getMessage());
        }
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_MAPPING_PATH_NOT_ALLOWED:owner.email');
        app(ConfigureInstitutionalFormatMapping::class)->execute($version->fresh(), [
            'schema_version' => 1,
            'placeholders' => ['TITLE' => 'owner.email', 'TOPIC' => 'planning.topic'],
        ], $this->admin());
    }

    public function test_render_de_muestra_es_privado_e_idempotente(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $admin = $this->admin();
        $first = app(RenderInstitutionalFormatSample::class)->execute($version, $admin);
        $second = app(RenderInstitutionalFormatSample::class)->execute($version->fresh(), $admin);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FormatVersionSample::query()->where('format_version_id', $version->id)->count());
        $this->assertSame(FormatSampleStatus::Pending, $first->status);
        $this->assertSame(FileCategory::FormatSample, $first->docxFile->category);
        $this->assertSame(FileCategory::FormatSample, $first->pdfFile->category);
        $this->assertNull($first->docxFile->request_id);
        $this->assertNull($first->pdfFile->request_id);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('private')->get($first->docxFile->path));
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('private')->get($first->pdfFile->path));
        $this->assertSame('sample_ready', $version->fresh()->validation_report['status']);
    }

    public function test_aprobar_muestra_permite_publicar_version_ready(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $admin = $this->admin();
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $admin);
        $approved = app(ReviewInstitutionalFormatSample::class)->approve($sample, $admin, 'DOCX y PDF revisados visualmente.');
        $published = app(PublishFormatVersion::class)->execute($version->fresh(), $admin);
        $this->assertSame(FormatSampleStatus::Approved, $approved->status);
        $this->assertSame('approved', $published->validation_report['status']);
        $this->assertSame($approved->id, $published->validation_report['sample']['id']);
        $this->assertNotNull($published->published_at);
        $this->assertSame(InstitutionalFormatStatus::Ready, $published->format->status);
        $this->assertSame('institutional-v1', $published->renderer);
    }

    public function test_rechazar_muestra_impide_publicacion(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $admin = $this->admin();
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $admin);
        app(ReviewInstitutionalFormatSample::class)->reject($sample, $admin, 'La tabla se desborda.');
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_VERSION_SAMPLE_NOT_APPROVED');
        app(PublishFormatVersion::class)->execute($version->fresh(), $admin);
    }

    public function test_renderer_reemplaza_tokens_sin_modificar_texto_estatico(): void
    {
        $owner = $this->customer();
        $version = $this->publishedInstitutionalFormat($owner->id);
        $source = $version->sourceFile;
        $this->assertNotNull($source);
        $this->assertStringContainsString('FORMATO INSTITUCIONAL DEMO', Storage::disk('private')->get($source->path));
        $this->assertSame('institutional-v1.0.0', $version->validation_report['sample']['renderer_version']);
    }

    public function test_actor_cliente_no_puede_analizar_configurar_ni_revisar_muestra(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id);
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_VERSION_ADMIN_REQUIRED');
        app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
    }
}
