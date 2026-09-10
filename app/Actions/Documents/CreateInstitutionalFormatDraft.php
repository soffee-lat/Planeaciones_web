<?php

namespace App\Actions\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Enums\RoleCode;
use App\Exceptions\DocumentFormatException;
use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
use App\Models\StoredFile;
use App\Models\User;
use App\Services\Documents\InstitutionalDocumentRenderer;
use App\Services\Documents\OfficeOpenXmlPackage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

final class CreateInstitutionalFormatDraft
{
    public const DOCX_MIME = 'application/vnd.openxmlformats-officedocument.wordprocessingml.document';

    public function execute(
        User $owner,
        string $name,
        string $originalName,
        string $bytes,
        User $actor,
    ): FormatVersion {
        $this->assertAdmin($actor);
        if (! $owner->hasRole(RoleCode::Customer)) {
            throw new DocumentFormatException('FORMAT_OWNER_CUSTOMER_REQUIRED');
        }

        $name = trim($name);
        $originalName = trim($originalName);
        $maxBytes = max(1, (int) config('documents.institutional_source_max_bytes', 10 * 1024 * 1024));
        if ($name === '' || mb_strlen($name) > 255) {
            throw new DocumentFormatException('FORMAT_NAME_INVALID');
        }
        if ($originalName === '' || mb_strlen($originalName) > 255 || strtolower(pathinfo($originalName, PATHINFO_EXTENSION)) !== 'docx') {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_REQUIRED');
        }
        if ($bytes === '' || strlen($bytes) > $maxBytes) {
            throw new DocumentFormatException('FORMAT_SOURCE_SIZE_INVALID');
        }

        $this->assertSafeDocxPackage($bytes);

        $disk = (string) config('documents.disk', 'private');
        $prefix = trim((string) config('documents.institutional_formats_prefix', 'documents/institutional-formats'), '/');
        $path = $prefix . '/' . $owner->id . '/' . Str::uuid() . '.docx';
        Storage::disk($disk)->put($path, $bytes);

        try {
            return DB::transaction(function () use ($owner, $actor, $name, $originalName, $bytes, $disk, $path): FormatVersion {
                $source = StoredFile::query()->create([
                    'owner_id' => $owner->id,
                    'request_id' => null,
                    'category' => FileCategory::InstitutionalFormat->value,
                    'disk' => $disk,
                    'path' => $path,
                    'original_name' => $originalName,
                    'detected_mime' => self::DOCX_MIME,
                    'size_bytes' => strlen($bytes),
                    'sha256' => hash('sha256', $bytes),
                    'scan_status' => FileScanStatus::Clean->value,
                    'retention_until' => null,
                    'purged_at' => null,
                    'uploaded_by' => $actor->id,
                ]);

                $format = InstitutionalFormat::query()->create([
                    'owner_id' => $owner->id,
                    'name' => $name,
                    'kind' => InstitutionalFormatKind::Institutional->value,
                    'status' => InstitutionalFormatStatus::PendingAnalysis->value,
                ]);

                return FormatVersion::query()->create([
                    'format_id' => $format->id,
                    'number' => 1,
                    'source_file_id' => $source->id,
                    'mapping' => (object) [],
                    'schema_version' => 1,
                    'renderer' => InstitutionalDocumentRenderer::FORMAT_RENDERER,
                    'validation_report' => ['status' => 'pending_analysis'],
                    'approved_by' => null,
                    'published_at' => null,
                ])->fresh(['format.owner', 'sourceFile']);
            }, attempts: 3);
        } catch (Throwable $error) {
            if (Storage::disk($disk)->exists($path)) {
                Storage::disk($disk)->delete($path);
            }
            throw $error;
        }
    }

    private function assertSafeDocxPackage(string $bytes): void
    {
        $package = OfficeOpenXmlPackage::fromBytes($bytes);
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $required) {
            if (! $package->has($required)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_REQUIRED_ENTRY_MISSING:' . $required);
            }
        }

        $contentTypes = $package->get('[Content_Types].xml');
        if (stripos($contentTypes, 'macroEnabled') !== false) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_ACTIVE_CONTENT');
        }
        foreach ($package->names() as $entry) {
            $lower = strtolower($entry);
            if (str_ends_with($lower, 'vbaproject.bin') || str_contains($lower, '/activex/')) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_ACTIVE_CONTENT');
            }
            if (str_ends_with($lower, '.rels')
                && preg_match('/TargetMode\s*=\s*["\']External["\']/i', $package->get($entry)) === 1) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_EXTERNAL_RELATIONSHIP');
            }
        }
    }

    private function assertAdmin(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Administrator)) {
            throw new DocumentFormatException('FORMAT_VERSION_ADMIN_REQUIRED');
        }
    }
}
