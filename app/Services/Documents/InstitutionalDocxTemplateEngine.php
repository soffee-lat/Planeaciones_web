<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;

final class InstitutionalDocxTemplateEngine
{
    /** @param array{anchors:array<string,string>,placeholders:array<string,string>} $values @param array<string,mixed> $analysis */
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

        $anchorsById = [];
        foreach (($analysis['anchors'] ?? []) as $anchor) {
            if (is_array($anchor) && is_string($anchor['id'] ?? null)) {
                $anchorsById[$anchor['id']] = $anchor;
            }
        }

        $cellValues = [];
        $paragraphValues = [];
        foreach ($values['anchors'] as $id => $value) {
            if (! isset($anchorsById[$id]) || trim($value) === '') {
                continue;
            }
            if (str_starts_with($id, 'c:')) {
                $cellValues[(int) substr($id, 2)] = $value;
            }
            if (str_starts_with($id, 'p:')) {
                $paragraphValues[(int) substr($id, 2)] = $value;
            }
        }

        if ($cellValues !== []) {
            $xml = $this->appendAtIndexes($xml, '/<w:tc\b[^>]*>.*?<\/w:tc>/s', $cellValues, '</w:tc>', true);
        }
        if ($paragraphValues !== []) {
            $xml = $this->appendAtIndexes($xml, '/<w:p\b[^>]*>.*?<\/w:p>/s', $paragraphValues, '</w:p>', false);
        }

        if (preg_match('/\{\{[A-Z][A-Z0-9_.-]{1,63}\}\}/', $xml) === 1) {
            throw new DocumentFormatException('FORMAT_TEMPLATE_UNMAPPED_PLACEHOLDER');
        }

        $package->replace('word/document.xml', $xml);

        return $package->toBytes();
    }

    /** @param array<int,string> $values */
    private function appendAtIndexes(string $xml, string $pattern, array $values, string $closingTag, bool $cell): string
    {
        $index = -1;

        return preg_replace_callback($pattern, function (array $match) use (&$index, $values, $closingTag, $cell): string {
            $index++;
            if (! array_key_exists($index, $values)) {
                return $match[0];
            }

            $value = trim($values[$index]);
            if ($value === '') {
                return $match[0];
            }

            $run = '<w:r><w:t xml:space="preserve"> ' . $this->xml($value) . '</w:t></w:r>';
            $addition = $cell ? '<w:p>' . $run . '</w:p>' : $run;

            return preg_replace('/' . preg_quote($closingTag, '/') . '$/', $addition . $closingTag, $match[0]) ?? $match[0];
        }, $xml) ?? $xml;
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
