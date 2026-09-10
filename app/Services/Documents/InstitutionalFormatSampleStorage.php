<?php

namespace App\Services\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

final class InstitutionalFormatSampleStorage
{
    /** @return array{docx:StoredFile,pdf:StoredFile} */
    public function persist(FormatVersion $version, string $fingerprint, string $docxBytes, string $pdfBytes): array
    {
        $version->loadMissing('format');
        $ownerId = $version->format?->owner_id;
        if (! $ownerId) {
            throw new DocumentFormatException('FORMAT_SAMPLE_OWNER_REQUIRED');
        }
        $disk = (string) config('documents.disk', 'private');
        $prefix = trim((string) config('documents.format_samples_prefix', 'documents/format-samples'), '/');
        $base = sprintf('%s/format-%d/v%d/%s', $prefix, $version->format_id, $version->number, $fingerprint);

        return [
            'docx' => $this->persistOne(
                $ownerId,
                $disk,
                $base . '/sample.docx',
                'muestra-formato-' . $version->format_id . '-v' . $version->number . '.docx',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                $docxBytes,
            ),
            'pdf' => $this->persistOne(
                $ownerId,
                $disk,
                $base . '/sample.pdf',
                'muestra-formato-' . $version->format_id . '-v' . $version->number . '.pdf',
                'application/pdf',
                $pdfBytes,
            ),
        ];
    }

    private function persistOne(int $ownerId, string $disk, string $path, string $name, string $mime, string $bytes): StoredFile
    {
        $storage = Storage::disk($disk);
        $sha = hash('sha256', $bytes);
        $existing = StoredFile::query()->where('path', $path)->first();
        if ($existing) {
            if ((int) $existing->owner_id !== $ownerId
                || $existing->category !== FileCategory::FormatSample
                || $existing->sha256 !== $sha
                || (int) $existing->size_bytes !== strlen($bytes)
                || $existing->scan_status !== FileScanStatus::Clean) {
                throw new DocumentFormatException('FORMAT_SAMPLE_STORAGE_CONFLICT');
            }
            if (! $storage->exists($path) || hash('sha256', $storage->get($path)) !== $sha) {
                throw new DocumentFormatException('FORMAT_SAMPLE_BYTES_CONFLICT');
            }

            return $existing;
        }

        if (! $storage->put($path, $bytes)) {
            throw new DocumentFormatException('FORMAT_SAMPLE_STORAGE_WRITE_FAILED');
        }

        try {
            return StoredFile::query()->create([
                'owner_id' => $ownerId,
                'request_id' => null,
                'category' => FileCategory::FormatSample->value,
                'disk' => $disk,
                'path' => $path,
                'original_name' => $name,
                'detected_mime' => $mime,
                'size_bytes' => strlen($bytes),
                'sha256' => $sha,
                'scan_status' => FileScanStatus::Clean->value,
                'uploaded_by' => null,
            ]);
        } catch (\Throwable $e) {
            $storage->delete($path);
            throw $e;
        }
    }
}
