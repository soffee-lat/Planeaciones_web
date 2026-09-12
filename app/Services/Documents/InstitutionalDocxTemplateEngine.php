<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;

final class InstitutionalDocxTemplateEngine
{
    /** @param array{anchors:array<string,string>,placeholders:array<string,string>,fragments?:array<string,array<string,mixed>>,structures?:array<string,array<string,mixed>>} $values @param array<string,mixed> $analysis */
    public function render(string $sourceBytes, array $values, array $analysis): string
    {
        $package = OfficeOpenXmlPackage::fromBytes($sourceBytes);
        $xml = $package->get('word/document.xml');

        foreach ($values['placeholders'] as $token => $value) {
            $needle = '{{' . $token . '}}';
            if (str_contains($xml, $needle)) {
                $xml = str_replace($needle, $this->xml($value), $xml);
            }
        }

        $fragmentCells = [];
        $fragmentParagraphs = [];
        foreach (($values['fragments'] ?? []) as $fragment) {
            if (! is_array($fragment)) {
                continue;
            }
            $zoneId = (string) ($fragment['zone_id'] ?? '');
            $value = trim((string) ($fragment['value'] ?? ''));
            if ($zoneId === '' || $value === '') {
                continue;
            }
            $operation = [
                'start' => (int) ($fragment['start'] ?? -1),
                'end' => (int) ($fragment['end'] ?? -1),
                'value' => $value,
                'source_text' => (string) ($fragment['source_text'] ?? ''),
            ];
            if ($operation['start'] < 0 || $operation['end'] <= $operation['start']) {
                continue;
            }
            if (str_starts_with($zoneId, 'c:')) {
                $fragmentCells[(int) substr($zoneId, 2)][] = $operation;
            } elseif (str_starts_with($zoneId, 'p:')) {
                $fragmentParagraphs[(int) substr($zoneId, 2)][] = $operation;
            }
        }

        // Precise value fragments are applied first. Their offsets refer to the
        // untouched source document and therefore must not see earlier whole-zone
        // replacements that could change the visible text length.
        if ($fragmentParagraphs !== []) {
            $xml = $this->applyFragmentOperations($xml, '/<w:p\b[^>]*>.*?<\/w:p>/s', $fragmentParagraphs);
        }
        if ($fragmentCells !== []) {
            $xml = $this->applyFragmentOperations($xml, '/<w:tc\b[^>]*>.*?<\/w:tc>/s', $fragmentCells);
        }

        $anchorsById = [];
        foreach (($analysis['anchors'] ?? []) as $anchor) {
            if (is_array($anchor) && is_string($anchor['id'] ?? null)) {
                $anchorsById[$anchor['id']] = $anchor;
            }
        }
        foreach (($analysis['document_zones'] ?? []) as $zone) {
            if (! is_array($zone) || ! is_string($zone['id'] ?? null) || isset($anchorsById[$zone['id']])) {
                continue;
            }
            $anchorsById[$zone['id']] = [
                'id' => $zone['id'],
                'kind' => $zone['kind'] ?? null,
                'label' => '',
                'target_id' => $zone['id'],
                'replacement_mode' => 'replace_target',
            ];
        }

        $cellOperations = [];
        $paragraphOperations = [];

        foreach ($values['anchors'] as $id => $value) {
            $value = trim($value);
            if ($value === '' || ! isset($anchorsById[$id])) {
                continue;
            }

            $anchor = $anchorsById[$id];
            $targetId = is_string($anchor['target_id'] ?? null) ? $anchor['target_id'] : $id;
            $operation = [
                'value' => $value,
                'mode' => (string) ($anchor['replacement_mode'] ?? 'replace_target'),
                'label' => (string) ($anchor['label'] ?? ''),
            ];

            if (str_starts_with($targetId, 'c:')) {
                $cellOperations[(int) substr($targetId, 2)] = $operation;
            } elseif (str_starts_with($targetId, 'p:')) {
                $paragraphOperations[(int) substr($targetId, 2)] = $operation;
            }
        }

        if ($paragraphOperations !== []) {
            $xml = $this->applyOperations(
                $xml,
                '/<w:p\b[^>]*>.*?<\/w:p>/s',
                $paragraphOperations,
                '</w:p>',
                false,
            );
        }
        if ($cellOperations !== []) {
            $xml = $this->applyOperations(
                $xml,
                '/<w:tc\b[^>]*>.*?<\/w:tc>/s',
                $cellOperations,
                '</w:tc>',
                true,
            );
        }

        // Las estructuras se aplican al final: los bindings simples no cambian el
        // número de filas/tablas, así que los índices r:N / t:N siguen apuntando
        // al OOXML original. Cada copia nace del fragmento original ya estilizado.
        if (($values['structures'] ?? []) !== []) {
            $xml = $this->applyStructures($xml, (array) $values['structures'], $analysis);
        }

        if (preg_match('/\{\{[A-Z][A-Z0-9_.-]{1,63}\}\}/', $xml) === 1) {
            throw new DocumentFormatException('FORMAT_TEMPLATE_UNMAPPED_PLACEHOLDER');
        }

        $package->replace('word/document.xml', $xml);

        return $package->toBytes();
    }

    /** @param array<string,array<string,mixed>> $structures @param array<string,mixed> $analysis */
    private function applyStructures(string $xml, array $structures, array $analysis): string
    {
        $zones = [];
        foreach ((array) ($analysis['structural_zones'] ?? []) as $zone) {
            if (is_array($zone) && is_string($zone['id'] ?? null)) {
                $zones[$zone['id']] = $zone;
            }
        }

        $rows = [];
        $tables = [];
        foreach ($structures as $structure) {
            if (! is_array($structure)) {
                continue;
            }
            $zoneId = (string) ($structure['zone_id'] ?? '');
            if (str_starts_with($zoneId, 'r:')) {
                $rows[(int) substr($zoneId, 2)] = $structure;
            } elseif (str_starts_with($zoneId, 't:')) {
                $tables[(int) substr($zoneId, 2)] = $structure;
            }
        }

        if ($rows !== []) {
            $xml = $this->cloneStructures(
                $xml,
                '/<w:tr\b[^>]*>.*?<\/w:tr>/s',
                $rows,
                $zones,
            );
        }
        if ($tables !== []) {
            $xml = $this->cloneStructures(
                $xml,
                '/<w:tbl\b[^>]*>.*?<\/w:tbl>/s',
                $tables,
                $zones,
            );
        }

        return $xml;
    }

    /** @param array<int,array<string,mixed>> $operations @param array<string,array<string,mixed>> $zones */
    private function cloneStructures(string $xml, string $pattern, array $operations, array $zones): string
    {
        $index = -1;

        return preg_replace_callback($pattern, function (array $match) use (&$index, $operations, $zones): string {
            $index++;
            if (! isset($operations[$index])) {
                return $match[0];
            }

            $structure = $operations[$index];
            $zoneId = (string) ($structure['zone_id'] ?? '');
            $zone = $zones[$zoneId] ?? null;
            if (! is_array($zone)) {
                throw new DocumentFormatException('FORMAT_TEMPLATE_STRUCTURE_STALE');
            }
            $childIds = array_values(array_map('strval', is_array($zone['child_zone_ids'] ?? null) ? $zone['child_zone_ids'] : []));
            if ($childIds === []) {
                throw new DocumentFormatException('FORMAT_TEMPLATE_STRUCTURE_STALE');
            }

            $items = is_array($structure['items'] ?? null) ? $structure['items'] : [];
            if ($items === []) {
                throw new DocumentFormatException('FORMAT_TEMPLATE_STRUCTURE_ITEMS_MISSING');
            }

            $copies = [];
            foreach ($items as $item) {
                if (! is_array($item)) {
                    continue;
                }
                $values = is_array($item['values'] ?? null) ? $item['values'] : [];
                $copies[] = $this->fillStructureCopy($match[0], $childIds, $values);
            }
            if ($copies === []) {
                throw new DocumentFormatException('FORMAT_TEMPLATE_STRUCTURE_ITEMS_MISSING');
            }

            return implode('', $copies);
        }, $xml) ?? $xml;
    }

    /** @param list<string> $childIds @param array<string,mixed> $values */
    private function fillStructureCopy(string $fragment, array $childIds, array $values): string
    {
        $operations = [];
        foreach ($childIds as $localIndex => $zoneId) {
            if (! array_key_exists($zoneId, $values)) {
                continue;
            }
            $operations[$localIndex] = [
                'value' => trim((string) $values[$zoneId]),
                'mode' => 'replace_target',
                'label' => '',
            ];
        }

        if ($operations === []) {
            return $fragment;
        }

        return $this->applyOperations(
            $fragment,
            '/<w:tc\b[^>]*>.*?<\/w:tc>/s',
            $operations,
            '</w:tc>',
            true,
        );
    }

    /** @param array<int,list<array{start:int,end:int,value:string,source_text:string}>> $operations */
    private function applyFragmentOperations(string $xml, string $pattern, array $operations): string
    {
        $index = -1;

        return preg_replace_callback($pattern, function (array $match) use (&$index, $operations): string {
            $index++;
            if (! isset($operations[$index])) {
                return $match[0];
            }

            $fragment = $match[0];
            $ranges = $operations[$index];
            usort($ranges, static fn (array $a, array $b): int => $b['start'] <=> $a['start']);

            foreach ($ranges as $range) {
                $visible = $this->visibleText($fragment);
                $selected = mb_substr($visible, $range['start'], $range['end'] - $range['start']);
                if ($selected === '' || trim($selected) !== trim($range['source_text'])) {
                    throw new DocumentFormatException('FORMAT_TEMPLATE_FRAGMENT_STALE');
                }
                $fragment = $this->replaceVisibleRange($fragment, $range['start'], $range['end'], $range['value']);
            }

            return $fragment;
        }, $xml) ?? $xml;
    }

    private function replaceVisibleRange(string $fragment, int $start, int $end, string $replacement): string
    {
        $cursor = 0;
        $inserted = false;

        $result = preg_replace_callback(
            '/(<w:t\b[^>]*>)(.*?)(<\/w:t>)/s',
            function (array $match) use (&$cursor, &$inserted, $start, $end, $replacement): string {
                $decoded = html_entity_decode(strip_tags((string) $match[2]), ENT_QUOTES | ENT_XML1, 'UTF-8');
                $length = mb_strlen($decoded);
                $nodeStart = $cursor;
                $nodeEnd = $cursor + $length;
                $cursor = $nodeEnd;

                if ($end <= $nodeStart || $start >= $nodeEnd) {
                    return $match[0];
                }

                $localStart = max(0, $start - $nodeStart);
                $localEnd = min($length, $end - $nodeStart);
                $before = mb_substr($decoded, 0, $localStart);
                $after = mb_substr($decoded, $localEnd);
                $middle = '';

                if (! $inserted) {
                    $middle = $replacement;
                    $inserted = true;
                }

                return $match[1] . $this->xml($before . $middle . $after) . $match[3];
            },
            $fragment,
        );

        if (! $inserted || ! is_string($result)) {
            throw new DocumentFormatException('FORMAT_TEMPLATE_FRAGMENT_STALE');
        }

        return $result;
    }

    private function visibleText(string $fragment): string
    {
        preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $fragment, $texts);
        $value = '';
        foreach ($texts[1] ?? [] as $text) {
            $value .= html_entity_decode(strip_tags((string) $text), ENT_QUOTES | ENT_XML1, 'UTF-8');
        }

        return $value;
    }

    /** @param array<int,array{value:string,mode:string,label:string}> $operations */
    private function applyOperations(string $xml, string $pattern, array $operations, string $closingTag, bool $cell): string
    {
        $index = -1;

        return preg_replace_callback($pattern, function (array $match) use (&$index, $operations, $closingTag, $cell): string {
            $index++;
            if (! isset($operations[$index])) {
                return $match[0];
            }

            $operation = $operations[$index];
            $value = trim($operation['value']);
            if ($value === '') {
                return $match[0];
            }

            return match ($operation['mode']) {
                'replace_target' => $this->replaceFragmentText($match[0], $value, $closingTag, $cell),
                'replace_after_label' => $this->replaceFragmentText(
                    $match[0],
                    rtrim(trim($operation['label']), ':') . ': ' . $value,
                    $closingTag,
                    $cell,
                ),
                default => $this->appendValue($match[0], $value, $closingTag, $cell),
            };
        }, $xml) ?? $xml;
    }

    private function replaceFragmentText(string $fragment, string $text, string $closingTag, bool $cell): string
    {
        if (preg_match('/<w:t\b[^>]*>.*?<\/w:t>/s', $fragment) !== 1) {
            return $this->appendValue($fragment, $text, $closingTag, $cell);
        }

        $first = true;
        $replaced = preg_replace_callback(
            '/(<w:t\b[^>]*>)(.*?)(<\/w:t>)/s',
            function (array $match) use (&$first, $text): string {
                if ($first) {
                    $first = false;

                    return $match[1] . $this->xml($text) . $match[3];
                }

                return $match[1] . $match[3];
            },
            $fragment,
        );

        return is_string($replaced) ? $replaced : $fragment;
    }

    private function appendValue(string $fragment, string $value, string $closingTag, bool $cell): string
    {
        $run = '<w:r><w:t xml:space="preserve"> ' . $this->xml($value) . '</w:t></w:r>';
        $addition = $cell ? '<w:p>' . $run . '</w:p>' : $run;

        return preg_replace('/' . preg_quote($closingTag, '/') . '$/', $addition . $closingTag, $fragment) ?? $fragment;
    }

    /** @return list<array{type:string,text:string}> */
    public function textBlocks(string $docxBytes): array
    {
        $package = OfficeOpenXmlPackage::fromBytes($docxBytes);
        $xml = $package->get('word/document.xml');
        preg_match_all('/<w:p\b[^>]*>(.*?)<\/w:p>/s', $xml, $paragraphs);

        $blocks = [];
        foreach ($paragraphs[1] ?? [] as $paragraph) {
            preg_match_all('/<w:t\b[^>]*>(.*?)<\/w:t>/s', $paragraph, $texts);
            $text = '';
            foreach ($texts[1] ?? [] as $fragment) {
                $text .= html_entity_decode(strip_tags((string) $fragment), ENT_QUOTES | ENT_XML1, 'UTF-8');
            }
            $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
            if ($text !== '') {
                $blocks[] = ['type' => $blocks === [] ? 'title' : 'paragraph', 'text' => $text];
            }
        }

        if ($blocks === []) {
            throw new DocumentFormatException('FORMAT_TEMPLATE_RENDERED_TEXT_EMPTY');
        }

        return $blocks;
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
