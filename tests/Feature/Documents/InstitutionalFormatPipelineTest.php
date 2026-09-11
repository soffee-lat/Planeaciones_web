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
use App\Services\Documents\OfficeOpenXmlPackage;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class InstitutionalFormatPipelineTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_analisis_detecta_campos_en_docx_normal_sin_placeholders(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, [], true);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
        $this->assertSame('mapping_ready', $version->validation_report['status']);
        $this->assertNotEmpty($version->validation_report['analysis']['anchors']);
        $this->assertSame([], $version->validation_report['analysis']['placeholders']);
        $this->assertTrue($version->validation_report['analysis']['has_tables']);
        $this->assertSame('blank_template', $version->validation_report['analysis']['source_content_mode']);
        $this->assertSame(2, $version->mapping['schema_version']);
        $this->assertNotEmpty($version->mapping['anchors']);
        $this->assertSame(InstitutionalFormatStatus::Configuring, $version->format->status);
    }

    public function test_planeacion_ya_llena_se_usa_como_evidencia_y_su_contenido_se_reemplaza(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraftFromBytes($owner->id, $this->institutionalFilledTemplateBytes());
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);

        $analysis = $version->validation_report['analysis'];
        $this->assertSame('filled_example', $analysis['source_content_mode']);
        $this->assertGreaterThanOrEqual(5, $analysis['existing_value_count']);
        $this->assertContains('filled_example_content_will_be_replaced', $analysis['warnings']);

        $pdaAnchor = collect($analysis['anchors'])->first(
            fn (array $anchor): bool => ($anchor['suggested_path'] ?? null) === 'curricular_alignment.pdas',
        );
        $this->assertIsArray($pdaAnchor);
        $this->assertTrue((bool) $pdaAnchor['has_existing_value']);
        $this->assertSame('PDA ANTERIOR QUE DEBE REEMPLAZARSE', $pdaAnchor['current_value_excerpt']);
        $this->assertSame('replace_target', $pdaAnchor['replacement_mode']);

        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        $docx = Storage::disk('private')->get($sample->docxFile->path);
        $xml = OfficeOpenXmlPackage::fromBytes($docx)->get('word/document.xml');

        $this->assertStringContainsString('FORMATO INSTITUCIONAL DEMO', $xml);
        $this->assertStringContainsString('PDA:', $xml);
        $this->assertStringContainsString('MUESTRA', $xml);
        $this->assertStringNotContainsString('PDA ANTERIOR QUE DEBE REEMPLAZARSE', $xml);
        $this->assertStringNotContainsString('ACTIVIDAD ANTERIOR DE INICIO', $xml);
        $this->assertStringNotContainsString('ACTIVIDAD ANTERIOR DE DESARROLLO', $xml);
        $this->assertStringNotContainsString('ACTIVIDAD ANTERIOR DE CIERRE', $xml);
        $this->assertStringNotContainsString('LISTA DE COTEJO ANTERIOR', $xml);
        $this->assertStringNotContainsString('PROPÓSITO ANTERIOR DEL PROYECTO', $xml);
    }

    public function test_placeholders_siguen_siendo_compatibles_pero_no_son_requisito(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id, ['TITLE', 'TOPIC'], false, []);
        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
        $this->assertSame(['TITLE', 'TOPIC'], $version->validation_report['analysis']['placeholders']);
        $this->assertSame('planning.title', $version->mapping['placeholders']['TITLE']);
        $this->assertSame('planning.topic', $version->mapping['placeholders']['TOPIC']);
    }

    public function test_mapping_exige_ancla_detectada_y_rutas_canonicas(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id);
        $anchor = (string) $version->validation_report['analysis']['anchors'][0]['id'];
        try {
            app(ConfigureInstitutionalFormatMapping::class)->execute($version, [
                'schema_version' => 2,
                'anchors' => ['p:99999' => 'planning.title'],
                'placeholders' => [],
            ], $owner);
            $this->fail('Se esperaba FORMAT_MAPPING_ANCHOR_MISMATCH.');
        } catch (DocumentFormatException $e) {
            $this->assertSame('FORMAT_MAPPING_ANCHOR_MISMATCH', $e->getMessage());
        }
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_MAPPING_PATH_NOT_ALLOWED:owner.email');
        app(ConfigureInstitutionalFormatMapping::class)->execute($version->fresh(), [
            'schema_version' => 2,
            'anchors' => [$anchor => 'owner.email'],
            'placeholders' => [],
        ], $owner);
    }

    public function test_render_de_muestra_es_privado_e_idempotente_para_propietario(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $first = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        $second = app(RenderInstitutionalFormatSample::class)->execute($version->fresh(), $owner);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, FormatVersionSample::query()->where('format_version_id', $version->id)->count());
        $this->assertSame(FormatSampleStatus::Pending, $first->status);
        $this->assertSame(FileCategory::FormatSample, $first->docxFile->category);
        $this->assertSame(FileCategory::FormatSample, $first->pdfFile->category);
        $this->assertStringStartsWith("PK\x03\x04", Storage::disk('private')->get($first->docxFile->path));
        $this->assertStringStartsWith('%PDF-1.4', Storage::disk('private')->get($first->pdfFile->path));
        $this->assertSame('sample_ready', $version->fresh()->validation_report['status']);
    }

    public function test_propietario_aprueba_muestra_y_activa_formato(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        $approved = app(ReviewInstitutionalFormatSample::class)->approve($sample, $owner, 'Se ve bien.');
        $published = app(PublishFormatVersion::class)->execute($version->fresh(), $owner);
        $this->assertSame(FormatSampleStatus::Approved, $approved->status);
        $this->assertSame('approved', $published->validation_report['status']);
        $this->assertNotNull($published->published_at);
        $this->assertSame($owner->id, $published->approved_by);
        $this->assertSame(InstitutionalFormatStatus::Ready, $published->format->status);
    }

    public function test_rechazar_muestra_impide_activar_formato(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        app(ReviewInstitutionalFormatSample::class)->reject($sample, $owner, 'El campo no quedó donde corresponde.');
        $this->expectException(DocumentFormatException::class);
        $this->expectExceptionMessage('FORMAT_VERSION_SAMPLE_NOT_APPROVED');
        app(PublishFormatVersion::class)->execute($version->fresh(), $owner);
    }

    public function test_renderer_conserva_texto_estatico_y_agrega_muestra_en_anclas(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        $bytes = Storage::disk('private')->get($sample->docxFile->path);
        $this->assertStringContainsString('FORMATO INSTITUCIONAL DEMO', $bytes);
        $this->assertStringContainsString('MUESTRA', $bytes);
    }

    public function test_otro_cliente_y_administrador_no_pueden_gestionar_formato_ajeno(): void
    {
        $owner = $this->customer();
        $draft = $this->institutionalDraft($owner->id);
        foreach ([$this->customer(), $this->admin()] as $actor) {
            try {
                app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version']->fresh(), $actor);
                $this->fail('Se esperaba FORMAT_OWNER_REQUIRED.');
            } catch (DocumentFormatException $e) {
                $this->assertSame('FORMAT_OWNER_REQUIRED', $e->getMessage());
            }
        }
    }
}
