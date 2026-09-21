<?php

namespace App\Services\Documents;

final class StandardPdfRenderer
{
    private const PAGE_WIDTH = 612;
    private const PAGE_HEIGHT = 792;
    private const LEFT = 54;
    private const TOP = 742;
    private const BOTTOM = 54;

    /** @param list<array<string,mixed>> $blocks */
    public function render(array $blocks): string
    {
        $pages = $this->paginate($this->flatten($blocks));
        $objects = [];
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[3] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica /Encoding /WinAnsiEncoding >>';
        $objects[4] = '<< /Type /Font /Subtype /Type1 /BaseFont /Helvetica-Bold /Encoding /WinAnsiEncoding >>';

        $kids = [];
        $next = 5;
        foreach ($pages as $pageIndex => $lines) {
            $pageObject = $next++;
            $streamObject = $next++;
            $kids[] = $pageObject . ' 0 R';
            $stream = $this->pageStream($lines, $pageIndex + 1, count($pages));
            $objects[$streamObject] = '<< /Length ' . strlen($stream) . " >>\nstream\n" . $stream . "\nendstream";
            $objects[$pageObject] = sprintf(
                '<< /Type /Page /Parent 2 0 R /MediaBox [0 0 %d %d] /Resources << /Font << /F1 3 0 R /F2 4 0 R >> >> /Contents %d 0 R >>',
                self::PAGE_WIDTH,
                self::PAGE_HEIGHT,
                $streamObject,
            );
        }
        $objects[2] = '<< /Type /Pages /Kids [' . implode(' ', $kids) . '] /Count ' . count($kids) . ' >>';

        ksort($objects);
        $pdf = "%PDF-1.4\n%\xE2\xE3\xCF\xD3\n";
        $offsets = [0 => 0];
        foreach ($objects as $number => $body) {
            $offsets[$number] = strlen($pdf);
            $pdf .= $number . " 0 obj\n" . $body . "\nendobj\n";
        }

        $xrefOffset = strlen($pdf);
        $max = max(array_keys($objects));
        $pdf .= "xref\n0 " . ($max + 1) . "\n";
        $pdf .= "0000000000 65535 f \n";
        for ($i = 1; $i <= $max; $i++) {
            $pdf .= sprintf('%010d 00000 n ', $offsets[$i]) . "\n";
        }
        $pdf .= 'trailer << /Size ' . ($max + 1) . ' /Root 1 0 R >>' . "\nstartxref\n" . $xrefOffset . "\n%%EOF\n";

        return $pdf;
    }

    /** @param list<array<string,mixed>> $blocks @return list<array{type:string,text:string}> */
    private function flatten(array $blocks): array
    {
        $out = [];
        $add = static function (array &$out, string $type, string $text): void {
            $text = trim($text);
            if ($text !== '') {
                $out[] = ['type' => $type, 'text' => $text];
            }
        };

        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? 'paragraph');
            if ($type === 'page_break') {
                $out[] = ['type' => 'page_break', 'text' => ''];
                continue;
            }
            if (in_array($type, ['title', 'subtitle', 'section', 'paragraph'], true)) {
                $add($out, $type, (string) ($block['text'] ?? ''));
                continue;
            }
            if ($type === 'session_header') {
                $add($out, 'session_header', (string) ($block['text'] ?? ''));
                $add($out, 'small', (string) ($block['meta'] ?? ''));
                continue;
            }
            if ($type === 'moment_header') {
                $label = (string) ($block['text'] ?? '');
                $meta = trim((string) ($block['meta'] ?? ''));
                $add($out, 'moment_header', $label . ($meta !== '' ? ' · ' . $meta : ''));
                continue;
            }
            if (in_array($type, ['callout', 'key_value', 'small_note'], true)) {
                $label = trim((string) ($block['label'] ?? ''));
                $text = trim((string) ($block['text'] ?? ''));
                $add($out, $type === 'callout' ? 'callout' : 'paragraph', ($label !== '' ? $label . ': ' : '') . $text);
                continue;
            }
            if (in_array($type, ['list', 'checklist'], true)) {
                $label = trim((string) ($block['label'] ?? ''));
                if ($label !== '') {
                    $add($out, 'heading3', $label);
                }
                foreach ((array) ($block['items'] ?? []) as $item) {
                    $add($out, 'bullet', ($type === 'checklist' ? '[ ] ' : '') . (string) $item);
                }
                continue;
            }
            if (in_array($type, ['meta_table', 'two_column_table'], true)) {
                if (($block['title'] ?? '') !== '') {
                    $add($out, 'heading3', (string) $block['title']);
                }
                foreach ((array) ($block['rows'] ?? []) as $row) {
                    $cells = is_array($row) ? array_values($row) : [];
                    if ($type === 'meta_table' && count($cells) >= 4) {
                        $add($out, 'table_row', (string) $cells[0] . ': ' . (string) $cells[1] . '   |   ' . (string) $cells[2] . ': ' . (string) $cells[3]);
                    } elseif (count($cells) >= 2) {
                        $add($out, 'table_row', (string) $cells[0] . ': ' . str_replace("\n", '; ', (string) $cells[1]));
                    }
                }
                continue;
            }
            if ($type === 'table') {
                $headers = array_map('strval', is_array($block['headers'] ?? null) ? $block['headers'] : []);
                if ($headers !== []) {
                    $add($out, 'table_header', implode(' | ', $headers));
                }
                foreach ((array) ($block['rows'] ?? []) as $row) {
                    $cells = is_array($row) ? array_map(fn ($v) => str_replace("\n", '; ', (string) $v), array_values($row)) : [];
                    $add($out, 'table_row', implode(' | ', $cells));
                }
                continue;
            }
            if ($type === 'activity') {
                $add($out, 'activity_title', (string) ($block['instruction'] ?? 'Actividad'));
                $add($out, 'paragraph', 'Docente: ' . (string) ($block['teacher_action'] ?? ''));
                $add($out, 'paragraph', 'Alumnos: ' . (string) ($block['student_action'] ?? ''));
                $organization = trim((string) ($block['organization'] ?? ''));
                if ($organization !== '') {
                    $add($out, 'small', 'Organización: ' . $organization);
                }
                foreach ([
                    'Materiales' => 'materials',
                    'Evidencia' => 'evidence',
                    'Observar' => 'checks',
                ] as $label => $key) {
                    $items = array_values(array_filter(array_map('strval', (array) ($block[$key] ?? []))));
                    if ($items !== []) {
                        $add($out, 'small', $label . ': ' . implode('; ', $items));
                    }
                }
                continue;
            }
            if ($type === 'instrument') {
                $add($out, 'heading2', (string) ($block['name'] ?? 'Instrumento'));
                $add($out, 'paragraph', (string) ($block['purpose'] ?? ''));
                $sessions = array_values(array_filter(array_map('strval', (array) ($block['sessions'] ?? []))));
                if ($sessions !== []) {
                    $add($out, 'small', 'Aplica a: ' . implode(', ', $sessions));
                }
                $scale = array_values(array_filter(array_map('strval', (array) ($block['scale'] ?? []))));
                foreach ((array) ($block['criteria'] ?? []) as $criterion) {
                    $suffix = $scale !== [] ? '  [' . implode(' / ', $scale) . ']' : '  [ ]';
                    $add($out, 'bullet', (string) $criterion . $suffix);
                }
                continue;
            }

            $add($out, 'paragraph', (string) ($block['text'] ?? ''));
        }

        return $out;
    }

    /**
     * @param list<array{type:string,text:string}> $blocks
     * @return list<list<array{font:string,size:float,text:string,leading:float,type:string}>>
     */
    private function paginate(array $blocks): array
    {
        $pages = [[]];
        $page = 0;
        $y = self::TOP;

        foreach ($blocks as $block) {
            if ($block['type'] === 'page_break') {
                if ($pages[$page] !== []) {
                    $page++;
                    $pages[$page] = [];
                }
                $y = self::TOP;
                continue;
            }

            [$font, $size, $leading, $before] = match ($block['type']) {
                'title' => ['F2', 18.0, 23.0, 0.0],
                'subtitle' => ['F1', 11.5, 16.0, 1.0],
                'section' => ['F2', 13.0, 18.0, 11.0],
                'session_header' => ['F2', 14.0, 19.0, 8.0],
                'moment_header' => ['F2', 11.0, 15.0, 7.0],
                'heading1' => ['F2', 14.0, 18.0, 10.0],
                'heading2' => ['F2', 12.0, 16.0, 8.0],
                'heading3' => ['F2', 10.5, 14.0, 5.0],
                'callout' => ['F1', 10.5, 14.0, 4.0],
                'activity_title' => ['F2', 10.5, 14.0, 5.0],
                'table_header' => ['F2', 8.8, 12.0, 4.0],
                'table_row' => ['F1', 8.8, 12.0, 1.0],
                'small' => ['F1', 8.8, 12.0, 1.0],
                'bullet' => ['F1', 10.0, 13.5, 1.0],
                default => ['F1', 10.0, 13.5, 1.0],
            };

            if ($y - $before < self::BOTTOM + $leading) {
                $page++;
                $pages[$page] = [];
                $y = self::TOP;
            }
            $y -= $before;

            $prefix = $block['type'] === 'bullet' ? '- ' : '';
            foreach ($this->wrap($prefix . $block['text'], $size) as $line) {
                if ($y < self::BOTTOM + $leading) {
                    $page++;
                    $pages[$page] = [];
                    $y = self::TOP;
                }
                $pages[$page][] = [
                    'font' => $font,
                    'size' => $size,
                    'text' => $line,
                    'leading' => $leading,
                    'type' => $block['type'],
                ];
                $y -= $leading;
            }
        }

        return array_values(array_filter($pages, static fn (array $lines): bool => $lines !== []));
    }

    /** @return list<string> */
    private function wrap(string $text, float $size): array
    {
        $maxChars = max(35, (int) floor(94 * (10.5 / $size)));
        $words = preg_split('/\s+/u', trim($text)) ?: [];
        $lines = [];
        $line = '';
        foreach ($words as $word) {
            if ($word === '') {
                continue;
            }
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            if ($this->textLength($candidate) <= $maxChars) {
                $line = $candidate;
                continue;
            }
            if ($line !== '') {
                $lines[] = $line;
            }
            while ($this->textLength($word) > $maxChars) {
                $lines[] = $this->textSubstr($word, 0, $maxChars);
                $word = $this->textSubstr($word, $maxChars);
            }
            $line = $word;
        }
        if ($line !== '') {
            $lines[] = $line;
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @param list<array{font:string,size:float,text:string,leading:float,type:string}> $lines */
    private function pageStream(array $lines, int $pageNumber, int $pageCount): string
    {
        $commands = [];
        $y = self::TOP;
        foreach ($lines as $line) {
            if (in_array($line['type'], ['section', 'session_header'], true)) {
                $commands[] = sprintf('0.94 g %d %.1F 504 %.1F re f 0 g', self::LEFT - 5, $y - 4, $line['leading'] + 4);
            } elseif (in_array($line['type'], ['moment_header', 'table_header'], true)) {
                $commands[] = sprintf('0.97 g %d %.1F 504 %.1F re f 0 g', self::LEFT - 3, $y - 3, $line['leading'] + 2);
            }
            $commands[] = sprintf(
                'BT /%s %.1F Tf %d %.1F Td (%s) Tj ET',
                $line['font'],
                $line['size'],
                self::LEFT,
                $y,
                $this->pdfText($line['text']),
            );
            $y -= $line['leading'];
        }
        $footer = 'Planeación didáctica · Página ' . $pageNumber . ' de ' . $pageCount;
        $commands[] = sprintf('BT /F1 8 Tf %d 30 Td (%s) Tj ET', self::LEFT, $this->pdfText($footer));

        return implode("\n", $commands);
    }

    private function textLength(string $text): int
    {
        if (function_exists('mb_strlen')) {
            return mb_strlen($text, 'UTF-8');
        }
        if (function_exists('iconv_strlen')) {
            $length = @iconv_strlen($text, 'UTF-8');
            if (is_int($length)) {
                return $length;
            }
        }
        return strlen($text);
    }

    private function textSubstr(string $text, int $start, ?int $length = null): string
    {
        if (function_exists('mb_substr')) {
            return mb_substr($text, $start, $length, 'UTF-8');
        }
        if (function_exists('iconv_substr')) {
            $slice = @iconv_substr($text, $start, $length, 'UTF-8');
            if (is_string($slice)) {
                return $slice;
            }
        }
        return $length === null ? substr($text, $start) : substr($text, $start, $length);
    }

    private function pdfText(string $text): string
    {
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'Windows-1252//TRANSLIT', $text);
            if (is_string($converted)) {
                $text = $converted;
            }
        } else {
            $text = preg_replace('/[^\x20-\x7E]/u', '?', $text) ?? $text;
        }

        return str_replace(['\\', '(', ')', "\r", "\n"], ['\\\\', '\\(', '\\)', ' ', ' '], $text);
    }
}
