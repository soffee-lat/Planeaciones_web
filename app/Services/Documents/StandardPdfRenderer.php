<?php

namespace App\Services\Documents;

final class StandardPdfRenderer
{
    private const PAGE_WIDTH = 612;
    private const PAGE_HEIGHT = 792;
    private const LEFT = 54;
    private const TOP = 742;
    private const BOTTOM = 54;

    /** @param list<array{type:string,text:string}> $blocks */
    public function render(array $blocks): string
    {
        $pages = $this->paginate($blocks);
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

    /**
     * @param list<array{type:string,text:string}> $blocks
     * @return list<list<array{font:string,size:float,text:string,leading:float}>>
     */
    private function paginate(array $blocks): array
    {
        $pages = [[]];
        $page = 0;
        $y = self::TOP;

        foreach ($blocks as $block) {
            [$font, $size, $leading, $before] = match ($block['type']) {
                'title' => ['F2', 18.0, 23.0, 0.0],
                'heading1' => ['F2', 14.0, 18.0, 10.0],
                'heading2' => ['F2', 12.0, 16.0, 8.0],
                'heading3' => ['F2', 11.0, 15.0, 6.0],
                'bullet' => ['F1', 10.5, 14.0, 1.0],
                default => ['F1', 10.5, 14.0, 1.0],
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
                $pages[$page][] = compact('font', 'size', 'line') + ['text' => $line, 'leading' => $leading];
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

    /** @param list<array{font:string,size:float,text:string,leading:float}> $lines */
    private function pageStream(array $lines, int $pageNumber, int $pageCount): string
    {
        $commands = [];
        $y = self::TOP;
        foreach ($lines as $line) {
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
        $footer = 'Página ' . $pageNumber . ' de ' . $pageCount;
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
