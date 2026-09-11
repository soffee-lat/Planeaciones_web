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
            if (str_ends_with($lower, '.rels')
                && preg_match('/TargetMode\s*=\s*["\']External["\']/i', $package->get($name)) === 1) {
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

        $this->collectTableAnchors($xml, $anchors, $suggestedAnchors, $usedPaths);
        $this->collectParagraphAnchors($xml, $anchors, $suggestedAnchors, $usedPaths);

        $suggestedPlaceholders = [];
        foreach ($placeholders as $token) {
            if ($path = $this->catalog->suggestToken($token)) {
                $suggestedPlaceholders[$token] = $path;
            }
        }

        $existingValueCount = count(array_filter(
            $anchors,
            static fn (array $anchor): bool => (bool) ($anchor['has_existing_value'] ?? false),
        ));

        $warnings = [];
        if (str_contains($xml, '<w:tbl')) {
            $warnings[] = 'tables_present_review_sample_visually';
        }
        if ($existingValueCount > 0) {
            $warnings[] = 'filled_example_content_will_be_replaced';
        }
        if ($anchors === [] && $placeholders === []) {
            $warnings[] = 'no_field_candidates_detected';
        } elseif ($suggestedAnchors === [] && $suggestedPlaceholders === []) {
            $warnings[] = 'no_automatic_mapping_detected';
        }

        return [
            'status' => 'analyzed',
            'mapping_strategy' => 'anchors_v2',
            'source_content_mode' => $existingValueCount > 0 ? 'filled_example' : 'blank_template',
            'existing_value_count' => $existingValueCount,
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
    private function collectTableAnchors(string $xml, array &$anchors, array &$suggested, array &$usedPaths): void
    {
        preg_match_all('/<w:tc\b[^>]*>.*?<\/w:tc>/s', $xml, $allCells);
        $cellTexts = [];
        foreach ($allCells[0] ?? [] as $index => $cell) {
            $cellTexts[$index] = $this->text((string) $cell);
        }

        $globalCellIndex = 0;
        preg_match_all('/<w:tr\b[^>]*>.*?<\/w:tr>/s', $xml, $rows);
        foreach ($rows[0] ?? [] as $row) {
            preg_match_all('/<w:tc\b[^>]*>.*?<\/w:tc>/s', (string) $row, $rowCells);
            $count = count($rowCells[0] ?? []);

            for ($local = 0; $local < $count; $local++) {
                $sourceIndex = $globalCellIndex + $local;
                $text = trim((string) ($cellTexts[$sourceIndex] ?? ''));
                if ($text === '') {
                    continue;
                }

                $inline = $this->splitInlineLabel($text);
                if ($inline !== null && trim($inline['value']) !== '') {
                    $this->addAnchor(
                        $anchors,
                        $suggested,
                        $usedPaths,
                        'c:' . $sourceIndex,
                        'cell',
                        $inline['label'],
                        'c:' . $sourceIndex,
                        'replace_after_label',
                        $inline['value'],
                        $inline['proposal'],
                    );
                    continue;
                }

                $label = $inline['label'] ?? $this->cleanLabel($text);
                $proposal = $inline['proposal'] ?? $this->catalog->suggest($label);
                if ($proposal === null && ! $this->looksLikeLabel($text)) {
                    continue;
                }

                $targetIndex = null;
                $targetValue = '';
                if ($local + 1 < $count) {
                    $candidateIndex = $sourceIndex + 1;
                    $candidateText = trim((string) ($cellTexts[$candidateIndex] ?? ''));
                    $candidateProposal = $candidateText === '' ? null : $this->catalog->suggest($this->cleanLabel($candidateText));
                    if ($candidateProposal === null) {
                        $targetIndex = $candidateIndex;
                        $targetValue = $candidateText;
                    }
                }

                $this->addAnchor(
                    $anchors,
                    $suggested,
                    $usedPaths,
                    'c:' . $sourceIndex,
                    'cell',
                    $label,
                    $targetIndex !== null ? 'c:' . $targetIndex : 'c:' . $sourceIndex,
                    $targetIndex !== null ? 'replace_target' : 'append_after_label',
                    $targetValue,
                    $proposal,
                );
            }

            $globalCellIndex += $count;
        }
    }

    /** @param list<array<string,mixed>> $anchors @param array<string,string> $suggested @param array<string,bool> $usedPaths */
    private function collectParagraphAnchors(string $xml, array &$anchors, array &$suggested, array &$usedPaths): void
    {
        preg_match_all('/<w:tbl\b[^>]*>.*?<\/w:tbl>/s', $xml, $tables, PREG_OFFSET_CAPTURE);
        $tableRanges = [];
        foreach ($tables[0] ?? [] as $table) {
            $start = (int) $table[1];
            $tableRanges[] = [$start, $start + strlen((string) $table[0])];
        }

        preg_match_all('/<w:p\b[^>]*>.*?<\/w:p>/s', $xml, $paragraphs, PREG_OFFSET_CAPTURE);
        $items = [];
        foreach ($paragraphs[0] ?? [] as $index => $paragraph) {
            $offset = (int) $paragraph[1];
            if ($this->insideRanges($offset, $tableRanges)) {
                continue;
            }
            $items[] = ['index' => (int) $index, 'text' => $this->text((string) $paragraph[0])];
        }

        $itemCount = count($items);
        for ($position = 0; $position < $itemCount; $position++) {
            $item = $items[$position];
            $text = trim((string) $item['text']);
            if ($text === '') {
                continue;
            }

            $inline = $this->splitInlineLabel($text);
            if ($inline !== null && trim($inline['value']) !== '') {
                $this->addAnchor(
                    $anchors,
                    $suggested,
                    $usedPaths,
                    'p:' . $item['index'],
                    'paragraph',
                    $inline['label'],
                    'p:' . $item['index'],
                    'replace_after_label',
                    $inline['value'],
                    $inline['proposal'],
                );
                continue;
            }

            $label = $inline['label'] ?? $this->cleanLabel($text);
            $proposal = $inline['proposal'] ?? $this->catalog->suggest($label);
            if ($proposal === null && ! $this->looksLikeLabel($text)) {
                continue;
            }

            $target = null;
            if ($position + 1 < $itemCount) {
                $next = $items[$position + 1];
                $nextText = trim((string) $next['text']);
                $nextProposal = $nextText === '' ? null : $this->catalog->suggest($this->cleanLabel($nextText));
                if ($nextProposal === null) {
                    $target = $next;
                }
            }

            $this->addAnchor(
                $anchors,
                $suggested,
                $usedPaths,
                'p:' . $item['index'],
                'paragraph',
                $label,
                $target !== null ? 'p:' . $target['index'] : 'p:' . $item['index'],
                $target !== null ? 'replace_target' : 'append_after_label',
                $target !== null ? (string) $target['text'] : '',
                $proposal,
            );
        }
    }

    /** @param list<array<string,mixed>> $anchors @param array<string,string> $suggested @param array<string,bool> $usedPaths @param array{path:string,confidence:int}|null $proposal */
    private function addAnchor(array &$anchors, array &$suggested, array &$usedPaths, string $id, string $kind, string $label, string $targetId, string $replacementMode, string $existingValue, ?array $proposal): void
    {
        $label = trim($label);
        if ($label === '' || mb_strlen($label) > 120) {
            return;
        }

        $existingValue = trim(preg_replace('/\s+/u', ' ', $existingValue) ?? $existingValue);
        $hasExistingValue = $existingValue !== '' && ! $this->looksLikeBlank($existingValue);

        $anchors[] = [
            'id' => $id,
            'kind' => $kind,
            'label' => $label,
            'target_id' => $targetId,
            'replacement_mode' => $replacementMode,
            'has_existing_value' => $hasExistingValue,
            'current_value_excerpt' => $hasExistingValue ? mb_substr($existingValue, 0, 160) : null,
            'suggested_path' => $proposal['path'] ?? null,
            'confidence' => $proposal['confidence'] ?? null,
        ];

        if ($proposal && ! isset($usedPaths[$proposal['path']])) {
            $suggested[$id] = $proposal['path'];
            $usedPaths[$proposal['path']] = true;
        }
    }

    /** @return array{label:string,value:string,proposal:array{path:string,confidence:int}}|null */
    private function splitInlineLabel(string $text): ?array
    {
        $colon = mb_strpos($text, ':');
        if ($colon === false) {
            return null;
        }

        $label = $this->cleanLabel(mb_substr($text, 0, $colon));
        $proposal = $this->catalog->suggest($label);
        if ($proposal === null) {
            return null;
        }

        return [
            'label' => $label,
            'value' => trim(mb_substr($text, $colon + 1)),
            'proposal' => $proposal,
        ];
    }

    private function cleanLabel(string $value): string
    {
        return trim(preg_replace('/\s+/u', ' ', $value) ?? $value, " :._\t\n\r");
    }

    private function looksLikeLabel(string $value): bool
    {
        $value = trim($value);
        return str_ends_with($value, ':') || preg_match('/_{3,}|\.{4,}$/u', $value) === 1;
    }

    private function looksLikeBlank(string $value): bool
    {
        return preg_match('/^(?:[_\.\-\s]+)$/u', $value) === 1;
    }

    /** @param list<array{0:int,1:int}> $ranges */
    private function insideRanges(int $offset, array $ranges): bool
    {
        foreach ($ranges as [$start, $end]) {
            if ($offset >= $start && $offset < $end) {
                return true;
            }
        }
        return false;
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
