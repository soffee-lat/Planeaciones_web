<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;
use App\Models\StoredFile;
use Illuminate\Support\Facades\Storage;

final class InstitutionalFormatStructureInspector
{
    /** @param array<string,mixed> $analysis @return array<string,mixed> */
    public function augment(array $analysis, StoredFile $file): array
    {
        $storage = Storage::disk($file->disk);
        if (! $storage->exists($file->path)) {
            throw new DocumentFormatException('FORMAT_SOURCE_BYTES_MISSING');
        }

        $bytes = $storage->get($file->path);
        if (! is_string($bytes) || hash('sha256', $bytes) !== $file->sha256) {
            throw new DocumentFormatException('FORMAT_SOURCE_HASH_MISMATCH');
        }

        $package = OfficeOpenXmlPackage::fromBytes($bytes);
        $xml = $package->get('word/document.xml');
        $structuralZones = $this->collect($xml);

        $documentZones = array_values(array_filter((array) ($analysis['document_zones'] ?? []), 'is_array'));
        $known = [];
        foreach ($documentZones as $zone) {
            if (is_string($zone['id'] ?? null)) {
                $known[$zone['id']] = true;
            }
        }
        foreach ($structuralZones as $zone) {
            if (! isset($known[$zone['id']])) {
                $documentZones[] = $zone;
            }
        }

        $analysis['document_zones'] = $documentZones;
        $analysis['structural_zones'] = $structuralZones;
        $analysis['structural_zone_count'] = count($structuralZones);
        $analysis['mapping_strategy'] = 'visual_structures_v1';

        return $analysis;
    }

    /** @return list<array<string,mixed>> */
    private function collect(string $xml): array
    {
        $zones = [];
        $rowIndex = 0;
        $cellIndex = 0;

        preg_match_all('/<w:tbl\b[^>]*>.*?<\/w:tbl>/s', $xml, $tables);
        foreach ($tables[0] ?? [] as $tableIndex => $table) {
            $tableRowIds = [];
            $tableCellIds = [];

            preg_match_all('/<w:tr\b[^>]*>.*?<\/w:tr>/s', (string) $table, $rows);
            foreach ($rows[0] ?? [] as $row) {
                $rowId = 'r:' . $rowIndex++;
                $rowCellIds = [];

                preg_match_all('/<w:tc\b[^>]*>.*?<\/w:tc>/s', (string) $row, $cells);
                foreach ($cells[0] ?? [] as $cell) {
                    $cellId = 'c:' . $cellIndex++;
                    $rowCellIds[] = $cellId;
                    $tableCellIds[] = $cellId;
                }

                $zones[] = [
                    'id' => $rowId,
                    'kind' => 'row',
                    'is_blank' => $this->text((string) $row) === '',
                    'text_excerpt' => $this->excerpt((string) $row),
                    'child_zone_ids' => $rowCellIds,
                ];
                $tableRowIds[] = $rowId;
            }

            $zones[] = [
                'id' => 't:' . $tableIndex,
                'kind' => 'table',
                'is_blank' => $this->text((string) $table) === '',
                'text_excerpt' => $this->excerpt((string) $table),
                'child_zone_ids' => $tableCellIds,
                'child_row_ids' => $tableRowIds,
            ];
        }

        return $zones;
    }

    private function excerpt(string $xml): ?string
    {
        $text = $this->text($xml);
        if ($text === '') {
            return null;
        }

        return mb_substr(preg_replace('/\s+/u', ' ', $text) ?? $text, 0, 220);
    }

    private function text(string $xml): string
    {
        preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $xml, $matches);
        $text = '';
        foreach ($matches[1] ?? [] as $fragment) {
            $text .= ' ' . html_entity_decode(strip_tags((string) $fragment), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
