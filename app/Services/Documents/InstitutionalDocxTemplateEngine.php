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
                'mode' => (string) ($anchor['replacement_mode'] ?? 'append_after_label'),
                'label' => (string) ($anchor['label'] ?? ''),
            ];

            if (str_starts_with($targetId, 'c:')) {
                $cellOperations[(int) substr($targetId, 2)] = $operation;
            } elseif (str_starts_with($targetId, 'p:')) {
                $paragraphOperations[(int) substr($targetId, 2)] = $operation;
            }
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
        if ($paragraphOperations !== []) {
            $xml = $this->applyOperations(
                $xml,
                '/<w:p\b[^>]*>.*?<\/w:p>/s',
                $paragraphOperations,
                '</w:p>',
                false,
            );
        }

        if (preg_match('/\{\{[A-Z][A-Z0-9_.-]{1,63}\}\}/', $xml) === 1) {
            throw new DocumentFormatException('FORMAT_TEMPLATE_UNMAPPED_PLACEHOLDER');
        }

        $package->replace('word/document.xml', $xml);

        return $package->toBytes();
    }

    /**
     * @param array<int,array{value:string,mode:string,label:string}> $operations
     */
    private function applyOperations(
        string $xml,
        string $pattern,
        array $operations,
        string $closingTag,
        bool $cell,
    ): string {
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
