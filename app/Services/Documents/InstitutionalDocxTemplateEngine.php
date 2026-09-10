<?php

namespace App\Services\Documents;

use App\Exceptions\DocumentFormatException;

final class InstitutionalDocxTemplateEngine
{
    /** @param array<string,string> $replacements */
    public function render(string $sourceBytes, array $replacements): string
    {
        $package = OfficeOpenXmlPackage::fromBytes($sourceBytes);
        $xml = $package->get('word/document.xml');

        foreach ($replacements as $token => $value) {
            $needle = '{{' . $token . '}}';
            if (! str_contains($xml, $needle)) {
                throw new DocumentFormatException('FORMAT_TEMPLATE_PLACEHOLDER_MISSING:' . $token);
            }
            $xml = str_replace($needle, $this->xml($value), $xml);
        }
        if (preg_match('/\{\{[A-Z][A-Z0-9_.-]{1,63}\}\}/', $xml) === 1) {
            throw new DocumentFormatException('FORMAT_TEMPLATE_UNMAPPED_PLACEHOLDER');
        }

        $package->replace('word/document.xml', $xml);

        return $package->toBytes();
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
