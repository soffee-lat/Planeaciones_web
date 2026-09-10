<?php

namespace Tests\Concerns;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\ConfigureInstitutionalFormatMapping;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\InstitutionalFormatStatus;
use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
use App\Models\StoredFile;
use App\Services\Documents\MinimalZipBuilder;
use Illuminate\Support\Facades\Storage;

trait CreatesInstitutionalFormatScenario
{
    protected function institutionalTemplateBytes(array $tokens = ['TITLE', 'TOPIC'], bool $withTable = false): string
    {
        $zip = new MinimalZipBuilder();
        $zip->add('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '</Types>');
        $zip->add('_rels/.rels', '<?xml version="1.0" encoding="UTF-8"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '</Relationships>');

        $paragraphs = '<w:p><w:r><w:t>FORMATO INSTITUCIONAL DEMO</w:t></w:r></w:p>';
        foreach ($tokens as $token) {
            $paragraphs .= '<w:p><w:r><w:t>{{' . $token . '}}</w:t></w:r></w:p>';
        }
        if ($withTable) {
            $paragraphs .= '<w:tbl><w:tr><w:tc><w:p><w:r><w:t>Tabla institucional</w:t></w:r></w:p></w:tc></w:tr></w:tbl>';
        }
        $zip->add('word/document.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $paragraphs . '<w:sectPr/></w:body></w:document>');

        return $zip->finish();
    }

    /** @return array{format:InstitutionalFormat,version:FormatVersion,source:StoredFile} */
    protected function institutionalDraft(int $ownerId, array $tokens = ['TITLE', 'TOPIC'], bool $withTable = false): array
    {
        Storage::fake('private');
        $bytes = $this->institutionalTemplateBytes($tokens, $withTable);
        $path = 'tests/institutional/' . fake()->uuid() . '.docx';
        Storage::disk('private')->put($path, $bytes);
        $source = StoredFile::factory()->create([
            'owner_id' => $ownerId,
            'request_id' => null,
            'uploaded_by' => $ownerId,
            'category' => FileCategory::InstitutionalFormat->value,
            'disk' => 'private',
            'path' => $path,
            'original_name' => 'formato-demo.docx',
            'detected_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'scan_status' => FileScanStatus::Clean->value,
        ]);
        $format = InstitutionalFormat::factory()->create([
            'owner_id' => $ownerId,
            'status' => InstitutionalFormatStatus::PendingAnalysis->value,
        ]);
        $version = FormatVersion::factory()->create([
            'format_id' => $format->id,
            'source_file_id' => $source->id,
            'mapping' => (object) [],
            'schema_version' => 1,
            'renderer' => 'institutional-v1',
            'validation_report' => ['status' => 'pending_analysis'],
        ]);

        return compact('format', 'version', 'source');
    }

    protected function analyzedInstitutional(int $ownerId, array $tokens = ['TITLE', 'TOPIC']): FormatVersion
    {
        $draft = $this->institutionalDraft($ownerId, $tokens);
        return app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $this->admin());
    }

    /** @param array<string,string>|null $paths */
    protected function configuredInstitutional(int $ownerId, ?array $paths = null): FormatVersion
    {
        $version = $this->analyzedInstitutional($ownerId);
        $paths ??= ['TITLE' => 'planning.title', 'TOPIC' => 'planning.topic'];
        return app(ConfigureInstitutionalFormatMapping::class)->execute($version, [
            'schema_version' => 1,
            'placeholders' => $paths,
        ], $this->admin());
    }

    protected function publishedInstitutionalFormat(int $ownerId): FormatVersion
    {
        $version = $this->configuredInstitutional($ownerId);
        $admin = $this->admin();
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $admin);
        app(ReviewInstitutionalFormatSample::class)->approve($sample, $admin, 'Muestra visual validada.');

        return app(PublishFormatVersion::class)->execute($version->fresh(), $admin);
    }
}
