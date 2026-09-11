<?php

namespace App\Services\Documents;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use App\Exceptions\DocumentFormatException;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

final class InstitutionalFormatSourceInspector
{
    public function __construct(private InstitutionalFormatFieldCatalog $catalog) {}

    /** @return array<string,mixed> */
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
            if (str_ends_with($lower, '.rels') && preg_match('/TargetMode\s*=\s*["\']External["\']/i', $package->get($name)) === 1) {
                throw new DocumentFormatException('FORMAT_SOURCE_DOCX_EXTERNAL_RELATIONSHIP');
            }
        }
        if (stripos($contentTypes, 'macroEnabled') !== false) {
            throw new DocumentFormatException('FORMAT_SOURCE_DOCX_ACTIVE_CONTENT');
        }

        $xml = $package->get('word/document.xml');
        preg_match_all('/\{\{([A-Z][A-Z0-9_.-]{1,63})\}\}/', $xml, $tokenMatches);
        $placeholders = array_values(array_unique(array_map('strval', $tokenMatches[1] ?? [])));
        sort($placeholders, SORT_STRING);

        $anchors = [];
        $suggestedAnchors = [];
        $usedPaths = [];
        preg_match_all('/<w:tc\b[^>]*>(.*?)<\/w:tc>/s', $xml, $cells);
        foreach ($cells[1] ?? [] as $index => $fragment) {
            $this->collectAnchor($anchors, $suggestedAnchors, $usedPaths, 'c:' . $index, 'cell', $this->text($fragment));
        }
        preg_match_all('/<w:p\b[^>]*>(.*?)<\/w:p>/s', $xml, $paragraphs);
        foreach ($paragraphs[1] ?? [] as $index => $fragment) {
            $this->collectAnchor($anchors, $suggestedAnchors, $usedPaths, 'p:' . $index, 'paragraph', $this->text($fragment));
        }

        $suggestedPlaceholders = [];
        foreach ($placeholders as $token) {
            if ($path = $this->catalog->suggestToken($token)) {
                $suggestedPlaceholders[$token] = $path;
            }
        }

        $warnings = [];
        if (str_contains($xml, '<w:tbl')) {
            $warnings[] = 'tables_present_review_sample_visually';
        }
        if ($anchors === [] && $placeholders === []) {
            $warnings[] = 'no_field_candidates_detected';
        } elseif ($suggestedAnchors === [] && $suggestedPlaceholders === []) {
            $warnings[] = 'no_automatic_mapping_detected';
        }

        return [
            'status' => 'analyzed',
            'mapping_strategy' => 'anchors_v1',
            'anchors' => $anchors,
            'placeholders' => $placeholders,
            'suggested_mapping' => [
                'schema_version' => 2,
                'anchors' => $suggestedAnchors,
                'placeholders' => $suggestedPlaceholders,
            ],
            'entry_count' => count($names),
            'has_tables' => str_contains($xml, '<w:tbl'),
            'warnings' => $warnings,
            'source_sha256' => $file->sha256,
        ];
    }

    /** @param list<array<string,mixed>> $anchors @param array<string,string> $suggested @param array<string,bool> $usedPaths */
    private function collectAnchor(array &$anchors, array &$suggested, array &$usedPaths, string $id, string $kind, string $label): void
    {
        $label = trim(preg_replace('/\s+/u', ' ', $label) ?? $label);
        if ($label === '' || mb_strlen($label) > 120) {
            return;
        }
        $proposal = $this->catalog->suggest($label);
        $looksLikeLabel = $proposal !== null || str_ends_with($label, ':') || preg_match('/_{3,}|\.{4,}$/u', $label) === 1;
        if (! $looksLikeLabel) {
            return;
        }
        $anchors[] = [
            'id' => $id,
            'kind' => $kind,
            'label' => rtrim($label, " :._\t"),
            'suggested_path' => $proposal['path'] ?? null,
            'confidence' => $proposal['confidence'] ?? null,
        ];
        if ($proposal && ! isset($usedPaths[$proposal['path']])) {
            $suggested[$id] = $proposal['path'];
            $usedPaths[$proposal['path']] = true;
        }
    }

    private function text(string $fragment): string
    {
        preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $fragment, $texts);
        $value = '';
        foreach ($texts[1] ?? [] as $text) {
            $value .= html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }
        return trim($value);
    }
}
