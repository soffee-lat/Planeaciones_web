<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\FormatSampleStatus;
use App\Enums\InstitutionalFormatStatus;
use App\Models\FormatVersionSample;
use App\Models\StoredFile;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class InstitutionalFormatIntegrityTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_bd_bloquea_publicacion_institucional_sin_muestra_aprobada_real(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $admin = $this->admin();
        $this->expectException(QueryException::class);
        DB::transaction(function () use ($version, $admin): void {
            $version->format->update(['status' => InstitutionalFormatStatus::Ready->value]);
            $version->forceFill([
                'validation_report' => [
                    'status' => 'approved',
                    'analysis' => $version->validation_report['analysis'],
                    'sample' => ['id' => 999999],
                ],
                'approved_by' => $admin->id,
                'published_at' => now(),
            ])->save();
        });
    }

    public function test_bd_bloquea_muestra_con_archivos_de_otro_propietario(): void
    {
        $owner = $this->customer();
        $other = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $docx = StoredFile::factory()->create([
            'owner_id' => $other->id,
            'category' => FileCategory::FormatSample->value,
            'scan_status' => FileScanStatus::Clean->value,
            'request_id' => null,
            'detected_mime' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        ]);
        $pdf = StoredFile::factory()->create([
            'owner_id' => $other->id,
            'category' => FileCategory::FormatSample->value,
            'scan_status' => FileScanStatus::Clean->value,
            'request_id' => null,
            'detected_mime' => 'application/pdf',
        ]);
        $this->expectException(QueryException::class);
        FormatVersionSample::query()->create([
            'format_version_id' => $version->id,
            'source_file_id' => $version->source_file_id,
            'mapping_snapshot' => $version->mapping,
            'renderer_version' => 'institutional-v1.0.0',
            'fingerprint' => str_repeat('a', 64),
            'docx_file_id' => $docx->id,
            'pdf_file_id' => $pdf->id,
            'status' => FormatSampleStatus::Pending->value,
            'created_by' => $this->admin()->id,
        ]);
    }

    public function test_muestra_aprobada_es_inmutable(): void
    {
        $owner = $this->customer();
        $version = $this->configuredInstitutional($owner->id);
        $admin = $this->admin();
        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $admin);
        $sample = app(ReviewInstitutionalFormatSample::class)->approve($sample, $admin, 'Aprobada.');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('FORMAT_SAMPLE_TERMINAL_IMMUTABLE');
        $sample->update(['review_note' => 'Intento de cambio posterior']);
    }
}
