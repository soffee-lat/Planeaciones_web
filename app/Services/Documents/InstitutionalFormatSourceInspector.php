<?php

namespace App\Services\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Exceptions\DocumentFormatException;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

final class InstitutionalFormatSourceInspector
{
    /** @return array{status:string,placeholders:list<string>,entry_count:int,has_tables:bool,warnings:list<string>,source_sha256:string} */
    public function inspect(StoredFile $file): array
    {
        if ($file->category !== FileCategory::InstitutionalFormat
            || $file->scan_status !== FileScanStatus::Clean
            || $file->purged_at !== null
            || $file->detected_mime !== 'application/vnd.openxmlformats-officedocument.wordprocessingml.document') {
            throw new DocumentFormatException('FORMAT_VERSION_SOURCE_NOT_READY');
        }
        $storage = Storage::disk($file->disk);
        if (! $storage->exists($file->path)) {
            throw new DocumentFormatException('FORMAT_SOURCE_BYTES_MISSING');
        }
        $bytes = $storage->get($file->path);
        if (! is_string($bytes) || hash('sha256', $bytes) !== $file->sha256) {
            throw new DocumentFormatException('FORMAT_SOURCE_HASH_MISMATCH');
        }

        $package = OfficeOpenXmlPackage::fromBytes($bytes);
        foreach (['[Content_Types].xml', '_rels/.rels', 'word/document.xml'] as $required) {
            if (! $package->has($required)) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_REQUIRED_ENTRY_MISSING:' . $required);
            }
        }

        $contentTypes = $package->get('[Content_Types].xml');
        $names = $package->names();
        foreach ($names as $name) {
            $lower = strtolower($name);
            if (str_ends_with($lower, 'vbaproject.bin') || str_contains($lower, '/activex/')) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_ACTIVE_CONTENT');
            }
            if (str_ends_with($lower, '.rels')) {
                $rels = $package->get($name);
                if (preg_match('/TargetMode\s*=\s*["\']External["\']/i', $rels) === 1) {
                    throw new DocumentFormatException('FORMAT_SOURCE_DOCX_EXTERNAL_RELATIONSHIP');
                }
            }
        }
        if (stripos($contentTypes, 'macroEnabled') !== false) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_ACTIVE_CONTENT');
        }

        $xml = $package->get('word/document.xml');
        preg_match_all('/\{\{([A-Z][A-Z0-9_.-]{1,63})\}\}/', $xml, $matches);
        $placeholders = array_values(array_unique(array_map('strval', $matches[1] ?? [])));
        sort($placeholders, SORT_STRING);
        if ($placeholders === []) {
            throw new DocumentFormatException('FORMAT_SOURCE_PLACEHOLDERS_REQUIRED');
        }

        $warnings = [];
        if (str_contains($xml, '<w:tbl')) {
            $warnings[] = 'tables_present_review_sample_visually';
        }
        if (preg_match('/\{\{(?![A-Z][A-Z0-9_.-]{1,63}\}\})/', $xml) === 1) {
            $warnings[] = 'unrecognized_placeholder_syntax';
        }

        return [
            'status' => 'analyzed',
            'placeholders' => $placeholders,
            'entry_count' => count($names),
            'has_tables' => str_contains($xml, '<w:tbl'),
            'warnings' => $warnings,
            'source_sha256' => $file->sha256,
        ];
    }
}
