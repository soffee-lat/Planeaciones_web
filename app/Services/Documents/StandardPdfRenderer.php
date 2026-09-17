<?php

namespace App\Services\Documents;

final class StandardPdfRenderer
{
    private const PAGE_WIDTH = 612;
    private const PAGE_HEIGHT = 792;
    private const LEFT = 54;
    private const RIGHT = 54;
    private const TOP = 742;
    private const BOTTOM = 54;
    private const CONTENT_WIDTH = self::PAGE_WIDTH - self::LEFT - self::RIGHT;

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
                continue;
            }

            if ($type === 'section') {
                $text = trim((string) ($block['text'] ?? ''));
                if ($text === 'Evaluación e instrumentos') {
                    $out[] = ['type' => 'page_break', 'text' => ''];
                }
                $add($out, 'section', $text);
                continue;
            }

            if (in_array($type, ['title', 'subtitle', 'paragraph'], true)) {
                $add($out, $type, (string) ($block['text'] ?? ''));
                continue;
            }

            if ($type === 'session_header') {
                $add($out, 'session_header', (string) ($block['text'] ?? ''));
                $add($out, 'session_meta', (string) ($block['meta'] ?? ''));
                continue;
            }

            if ($type === 'moment_header') {
                $label = strtoupper(trim((string) ($block['text'] ?? '')));
                $meta = trim((string) ($block['meta'] ?? ''));
                $add($out, 'moment_header', $label . ($meta !== '' ? ' · ' . $meta : ''));
                continue;
            }

            if ($type === 'callout') {
                $label = trim((string) ($block['label'] ?? ''));
                $text = trim((string) ($block['text'] ?? ''));
                $add($out, 'callout', ($label !== '' ? $label . "\n" : '') . $text);
                continue;
            }

            if (in_array($type, ['key_value', 'small_note'], true)) {
                $label = trim((string) ($block['label'] ?? ''));
                $text = trim((string) ($block['text'] ?? ''));
                $add($out, $type, ($label !== '' ? $label . ': ' : '') . $text);
                continue;
            }

            if (in_array($type, ['list', 'checklist'], true)) {
                $label = trim((string) ($block['label'] ?? ''));
                if ($label !== '') {
                    $add($out, 'heading3', $label);
                }
                foreach ((array) ($block['items'] ?? []) as $item) {
                    $add($out, $type === 'checklist' ? 'checklist_item' : 'bullet', (string) $item);
                }
                continue;
            }

            if ($type === 'meta_table') {
                foreach ((array) ($block['rows'] ?? []) as $row) {
                    $cells = is_array($row) ? array_values($row) : [];
                    if (count($cells) >= 4) {
                        $add(
                            $out,
                            'meta_row',
                            (string) $cells[0] . ': ' . (string) $cells[1]
                                . '    ·    '
                                . (string) $cells[2] . ': ' . (string) $cells[3],
                        );
                    }
                }
                continue;
            }

            if ($type === 'two_column_table') {
                if (($block['title'] ?? '') !== '') {
                    $add($out, 'heading3', (string) $block['title']);
                }
                foreach ((array) ($block['rows'] ?? []) as $row) {
                    $cells = is_array($row) ? array_values($row) : [];
                    if (count($cells) >= 2) {
                        $add($out, 'two_col_row', (string) $cells[0] . ': ' . str_replace("\n", '; ', (string) $cells[1]));
                    }
                }
                continue;
            }

            if ($type === 'table') {
                $headers = array_map('strval', is_array($block['headers'] ?? null) ? $block['headers'] : []);
                if ($headers === ['Fecha', 'Sesión', 'Objetivo', 'Actividad central', 'Evidencia']) {
                    foreach ((array) ($block['rows'] ?? []) as $row) {
                        $cells = is_array($row) ? array_values($row) : [];
                        if (count($cells) < 5) {
                            continue;
                        }
                        $add($out, 'weekly_session', trim((string) $cells[0] . ' · ' . (string) $cells[1]));
                        $add($out, 'weekly_detail', 'Objetivo: ' . (string) $cells[2]);
                        $add($out, 'weekly_detail', 'Actividad central: ' . (string) $cells[3]);
                        $add($out, 'weekly_detail', 'Evidencia: ' . (string) $cells[4]);
                        $out[] = ['type' => 'spacer', 'text' => ''];
                    }
                    continue;
                }

                if ($headers !== []) {
                    $add($out, 'table_header', implode(' · ', $headers));
                }
                foreach ((array) ($block['rows'] ?? []) as $row) {
                    $cells = is_array($row) ? array_map(
                        static fn ($value): string => str_replace("\n", '; ', (string) $value),
                        array_values($row),
                    ) : [];
                    $add($out, 'table_row', implode(' · ', $cells));
                }
                continue;
            }

            if ($type === 'activity') {
                $add($out, 'activity_title', (string) ($block['instruction'] ?? 'Actividad'));
                $add($out, 'activity_label', 'DOCENTE');
                $add($out, 'activity_body', (string) ($block['teacher_action'] ?? '—'));
                $add($out, 'activity_label', 'ALUMNOS');
                $add($out, 'activity_body', (string) ($block['student_action'] ?? '—'));

                $organization = trim((string) ($block['organization'] ?? ''));
                if ($organization !== '') {
                    $add($out, 'activity_detail', 'Organización: ' . $organization);
                }
                foreach ([
                    'Materiales' => 'materials',
                    'Evidencia' => 'evidence',
                    'Observar' => 'checks',
                ] as $label => $key) {
                    $items = array_values(array_filter(array_map('strval', (array) ($block[$key] ?? []))));
                    if ($items !== []) {
                        $add($out, 'activity_detail', $label . ': ' . implode(' · ', $items));
                    }
                }
                continue;
            }

            if ($type === 'instrument') {
                $add($out, 'instrument_heading', (string) ($block['name'] ?? 'Instrumento'));
                $add($out, 'paragraph', (string) ($block['purpose'] ?? ''));
                $sessions = array_values(array_filter(array_map('strval', (array) ($block['sessions'] ?? []))));
                if ($sessions !== []) {
                    $add($out, 'small_note', 'Aplica a: ' . implode(', ', $sessions));
                }
                $scale = array_values(array_filter(array_map('strval', (array) ($block['scale'] ?? []))));
                foreach ((array) ($block['criteria'] ?? []) as $criterion) {
                    $choices = $scale !== []
                        ? implode('    ', array_map(static fn (string $item): string => '[ ] ' . $item, $scale))
                        : '[ ] Registro';
                    $add($out, 'instrument_criterion', (string) $criterion . "\n" . $choices);
                }
                continue;
            }

            $add($out, 'paragraph', (string) ($block['text'] ?? ''));
        }

        return $out;
    }

    /**
     * @param list<array{type:string,text:string}> $blocks
     * @return list<list<array{font:string,size:float,text:string,leading:float,type:string,indent:float,fill:?float,border:bool,align:string,text_gray:float}>>
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

            if ($block['type'] === 'spacer') {
                $y -= 5.0;
                continue;
            }

            $style = $this->styleFor($block['type']);
            $minimum = match ($block['type']) {
                'session_header' => 82.0,
                'moment_header', 'activity_title' => 55.0,
                'section', 'instrument_heading', 'heading3' => 42.0,
                default => $style['leading'],
            };
            if ($y - $style['before'] < self::BOTTOM + $minimum) {
                $page++;
                $pages[$page] = [];
                $y = self::TOP;
            }
            $y -= $style['before'];

            $prefix = match ($block['type']) {
                'bullet' => '• ',
                'checklist_item' => '[ ] ',
                default => '',
            };
            foreach ($this->wrap($prefix . $block['text'], $style['size'], $style['indent']) as $line) {
                if ($y < self::BOTTOM + $style['leading']) {
                    $page++;
                    $pages[$page] = [];
                    $y = self::TOP;
                }
                $pages[$page][] = [
                    'font' => $style['font'],
                    'size' => $style['size'],
                    'text' => $line,
                    'leading' => $style['leading'],
                    'type' => $block['type'],
                    'indent' => $style['indent'],
                    'fill' => $style['fill'],
                    'border' => $style['border'],
                    'align' => $style['align'],
                    'text_gray' => $style['text_gray'],
                ];
                $y -= $style['leading'];
            }
            $y -= $style['after'];
        }

        return array_values(array_filter($pages, static fn (array $lines): bool => $lines !== []));
    }

    /** @return array{font:string,size:float,leading:float,before:float,after:float,indent:float,fill:?float,border:bool,align:string,text_gray:float} */
    private function styleFor(string $type): array
    {
        return match ($type) {
            'title' => $this->style('F2', 17.0, 22.0, 0.0, 5.0, 0.0, null, false, 'center', 0.08),
            'subtitle' => $this->style('F1', 11.5, 16.0, 0.0, 7.0, 0.0, null, false, 'center', 0.35),
            'section' => $this->style('F2', 12.0, 17.0, 10.0, 4.0, 0.0, 0.92, false, 'left', 0.18),
            'session_header' => $this->style('F2', 13.2, 18.0, 9.0, 2.0, 0.0, 0.89, true, 'left', 0.14),
            'session_meta' => $this->style('F1', 9.2, 12.0, 0.0, 4.0, 4.0, null, false, 'left', 0.35),
            'moment_header' => $this->style('F2', 10.3, 14.0, 6.0, 2.0, 0.0, 0.95, false, 'left', 0.18),
            'callout' => $this->style('F1', 9.8, 13.0, 4.0, 5.0, 7.0, 0.97, true, 'left', 0.15),
            'key_value' => $this->style('F1', 9.5, 13.0, 2.0, 2.0, 0.0, null, false, 'left', 0.10),
            'small_note' => $this->style('F1', 8.7, 11.8, 2.0, 2.0, 5.0, 0.985, false, 'left', 0.35),
            'heading3' => $this->style('F2', 10.3, 14.0, 5.0, 2.0, 0.0, null, false, 'left', 0.18),
            'bullet', 'checklist_item' => $this->style('F1', 9.3, 12.3, 1.0, 1.0, 10.0, null, false, 'left', 0.10),
            'meta_row' => $this->style('F1', 9.0, 12.0, 1.0, 1.0, 7.0, 0.97, true, 'left', 0.16),
            'two_col_row' => $this->style('F1', 9.0, 12.0, 1.0, 1.0, 7.0, 0.985, true, 'left', 0.16),
            'weekly_session' => $this->style('F2', 9.6, 13.0, 3.0, 0.0, 7.0, 0.92, true, 'left', 0.14),
            'weekly_detail' => $this->style('F1', 8.8, 11.7, 0.0, 0.0, 14.0, 0.985, false, 'left', 0.18),
            'table_header' => $this->style('F2', 9.0, 12.0, 3.0, 1.0, 7.0, 0.92, true, 'left', 0.14),
            'table_row' => $this->style('F1', 8.8, 11.8, 0.0, 1.0, 7.0, 0.985, true, 'left', 0.16),
            'activity_title' => $this->style('F2', 10.0, 13.5, 5.0, 1.0, 7.0, 0.95, true, 'left', 0.10),
            'activity_label' => $this->style('F2', 8.6, 11.0, 1.0, 0.0, 12.0, null, false, 'left', 0.28),
            'activity_body' => $this->style('F1', 9.2, 12.2, 0.0, 1.0, 18.0, null, false, 'left', 0.10),
            'activity_detail' => $this->style('F1', 8.5, 11.3, 0.0, 0.0, 12.0, 0.98, false, 'left', 0.34),
            'instrument_heading' => $this->style('F2', 11.3, 15.0, 6.0, 2.0, 0.0, 0.92, true, 'left', 0.16),
            'instrument_criterion' => $this->style('F1', 9.0, 12.0, 2.0, 2.0, 7.0, 0.985, true, 'left', 0.12),
            default => $this->style('F1', 9.5, 13.0, 1.0, 2.0, 0.0, null, false, 'left', 0.10),
        };
    }

    /** @return array{font:string,size:float,leading:float,before:float,after:float,indent:float,fill:?float,border:bool,align:string,text_gray:float} */
    private function style(
        string $font,
        float $size,
        float $leading,
        float $before,
        float $after,
        float $indent,
        ?float $fill,
        bool $border,
        string $align,
        float $textGray,
    ): array {
        return compact('font', 'size', 'leading', 'before', 'after', 'indent', 'fill', 'border', 'align', 'textGray') + [
            'text_gray' => $textGray,
        ];
    }

    /** @return list<string> */
    private function wrap(string $text, float $size, float $indent = 0.0): array
    {
        $availableRatio = max(0.45, (self::CONTENT_WIDTH - $indent) / self::CONTENT_WIDTH);
        $maxChars = max(32, (int) floor(92 * (10.5 / $size) * $availableRatio));
        $paragraphs = preg_split('/\R/u', trim($text)) ?: [$text];
        $lines = [];

        foreach ($paragraphs as $paragraph) {
            $words = preg_split('/\s+/u', trim((string) $paragraph)) ?: [];
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
        }

        return $lines === [] ? [''] : $lines;
    }

    /** @param list<array{font:string,size:float,text:string,leading:float,type:string,indent:float,fill:?float,border:bool,align:string,text_gray:float}> $lines */
    private function pageStream(array $lines, int $pageNumber, int $pageCount): string
    {
        $commands = [];
        $y = self::TOP;
        foreach ($lines as $line) {
            $x = self::LEFT + $line['indent'];
            $width = self::CONTENT_WIDTH - $line['indent'];
            $height = $line['leading'] + 3.0;

            if ($line['fill'] !== null) {
                $commands[] = sprintf('%.3F g %.1F %.1F %.1F %.1F re f 0 g', $line['fill'], $x - 4, $y - 4, $width + 8, $height);
            }
            if ($line['border']) {
                $commands[] = sprintf('0.82 G 0.45 w %.1F %.1F %.1F %.1F re S 0 G', $x - 4, $y - 4, $width + 8, $height);
            }
            if (in_array($line['type'], ['activity_label', 'activity_body', 'activity_detail'], true)) {
                $commands[] = sprintf('0.78 G 0.7 w %.1F %.1F m %.1F %.1F l S 0 G', self::LEFT + 4, $y - 4, self::LEFT + 4, $y + $line['leading'] - 3);
            }

            if ($line['align'] === 'center') {
                $estimated = min($width, $this->textLength($line['text']) * $line['size'] * 0.48);
                $x = self::LEFT + (($width - $estimated) / 2.0);
            }

            $commands[] = sprintf(
                '%.3F g BT /%s %.1F Tf %.1F %.1F Td (%s) Tj ET 0 g',
                $line['text_gray'],
                $line['font'],
                $line['size'],
                $x,
                $y,
                $this->pdfText($line['text']),
            );
            $y -= $line['leading'];
        }

        $commands[] = sprintf('0.82 G 0.4 w %d 42 m %d 42 l S 0 G', self::LEFT, self::PAGE_WIDTH - self::RIGHT);
        $footer = 'Planeación didáctica · Página ' . $pageNumber . ' de ' . $pageCount;
        $commands[] = sprintf('0.45 g BT /F1 8 Tf %d 28 Td (%s) Tj ET 0 g', self::LEFT, $this->pdfText($footer));

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
