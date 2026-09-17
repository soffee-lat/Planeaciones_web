<?php

namespace App\Services\Documents;

final class StandardDocxRenderer
{
    /** @param list<array<string,mixed>> $blocks */
    public function render(array $blocks): string
    {
        $zip = new MinimalZipBuilder();
        $zip->add('[Content_Types].xml', $this->contentTypes());
        $zip->add('_rels/.rels', $this->rootRelationships());
        $zip->add('docProps/core.xml', $this->coreProperties());
        $zip->add('docProps/app.xml', $this->appProperties());
        $zip->add('word/styles.xml', $this->styles());
        $zip->add('word/document.xml', $this->documentXml($blocks));

        return $zip->finish();
    }

    private function contentTypes(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
            . '<Override PartName="/word/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.styles+xml"/>'
            . '<Override PartName="/docProps/core.xml" ContentType="application/vnd.openxmlformats-package.core-properties+xml"/>'
            . '<Override PartName="/docProps/app.xml" ContentType="application/vnd.openxmlformats-officedocument.extended-properties+xml"/>'
            . '</Types>';
    }

    private function rootRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
            . '<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/package/2006/relationships/metadata/core-properties" Target="docProps/core.xml"/>'
            . '<Relationship Id="rId3" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/extended-properties" Target="docProps/app.xml"/>'
            . '</Relationships>';
    }

    private function coreProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<cp:coreProperties xmlns:cp="http://schemas.openxmlformats.org/package/2006/metadata/core-properties" '
            . 'xmlns:dc="http://purl.org/dc/elements/1.1/" xmlns:dcterms="http://purl.org/dc/terms/" '
            . 'xmlns:dcmitype="http://purl.org/dc/dcmitype/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">'
            . '<dc:title>Planeación didáctica</dc:title><dc:creator>Planeaciones</dc:creator>'
            . '<cp:lastModifiedBy>Planeaciones</cp:lastModifiedBy>'
            . '<dcterms:created xsi:type="dcterms:W3CDTF">2000-01-01T00:00:00Z</dcterms:created>'
            . '<dcterms:modified xsi:type="dcterms:W3CDTF">2000-01-01T00:00:00Z</dcterms:modified>'
            . '</cp:coreProperties>';
    }

    private function appProperties(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Properties xmlns="http://schemas.openxmlformats.org/officeDocument/2006/extended-properties" '
            . 'xmlns:vt="http://schemas.openxmlformats.org/officeDocument/2006/docPropsVTypes">'
            . '<Application>Planeaciones</Application><AppVersion>2.0</AppVersion>'
            . '</Properties>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Aptos" w:hAnsi="Aptos"/><w:sz w:val="21"/><w:lang w:val="es-MX"/></w:rPr></w:rPrDefault></w:docDefaults>'
            . $this->styleXml('Normal', 'Normal', 21, false, 0, 100)
            . $this->styleXml('Title', 'Título', 34, true, 0, 80)
            . $this->styleXml('Subtitle', 'Subtítulo', 23, false, 0, 180)
            . $this->styleXml('Section', 'Sección', 24, true, 180, 80)
            . $this->styleXml('Session', 'Sesión', 28, true, 0, 80)
            . $this->styleXml('Moment', 'Momento', 22, true, 120, 60)
            . $this->styleXml('Small', 'Texto pequeño', 18, false, 40, 60)
            . '</w:styles>';
    }

    private function styleXml(string $id, string $name, int $size, bool $bold, int $before, int $after): string
    {
        return '<w:style w:type="paragraph" w:styleId="' . $id . '">'
            . '<w:name w:val="' . $this->xml($name) . '"/>'
            . '<w:pPr><w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="260" w:lineRule="auto"/></w:pPr>'
            . '<w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:sz w:val="' . $size . '"/></w:rPr>'
            . '</w:style>';
    }

    /** @param list<array<string,mixed>> $blocks */
    private function documentXml(array $blocks): string
    {
        $body = '';
        foreach ($blocks as $block) {
            $type = (string) ($block['type'] ?? 'paragraph');
            if ($type === 'page_break') {
                continue;
            }

            $body .= match ($type) {
                'title' => $this->paragraph((string) ($block['text'] ?? ''), 'Title', true, null, '1F2937', 'center', true),
                'subtitle' => $this->paragraph((string) ($block['text'] ?? ''), 'Subtitle', false, null, '4B5563', 'center'),
                'section' => $this->paragraph(
                    (string) ($block['text'] ?? ''),
                    'Section',
                    true,
                    'E8EEF5',
                    '1F4E78',
                    null,
                    true,
                    trim((string) ($block['text'] ?? '')) === 'Evaluación e instrumentos',
                ),
                'meta_table' => $this->metaTable((array) ($block['rows'] ?? [])),
                'two_column_table' => $this->twoColumnTable((array) ($block['rows'] ?? []), (string) ($block['title'] ?? '')),
                'table' => $this->dataTable((array) ($block['headers'] ?? []), (array) ($block['rows'] ?? [])),
                'callout' => $this->callout((string) ($block['label'] ?? ''), (string) ($block['text'] ?? '')),
                'key_value' => $this->keyValue((string) ($block['label'] ?? ''), (string) ($block['text'] ?? '')),
                'small_note' => $this->smallNote((string) ($block['label'] ?? ''), (string) ($block['text'] ?? '')),
                'list' => $this->listBlock((string) ($block['label'] ?? ''), (array) ($block['items'] ?? []), false),
                'checklist' => $this->listBlock((string) ($block['label'] ?? ''), (array) ($block['items'] ?? []), true),
                'session_header' => $this->sessionHeader((string) ($block['text'] ?? ''), (string) ($block['meta'] ?? '')),
                'moment_header' => $this->momentHeader((string) ($block['text'] ?? ''), (string) ($block['meta'] ?? '')),
                'activity' => $this->activityBlock($block),
                'instrument' => $this->instrumentBlock($block),
                default => $this->paragraph((string) ($block['text'] ?? ''), 'Normal'),
            };
        }

        $body .= '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="720" w:right="720" w:bottom="720" w:left="720" w:header="420" w:footer="420"/></w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body
            . '</w:body></w:document>';
    }

    private function paragraph(
        string $text,
        string $style = 'Normal',
        bool $bold = false,
        ?string $fill = null,
        ?string $color = null,
        ?string $align = null,
        bool $keepNext = false,
        bool $pageBreakBefore = false,
    ): string {
        $text = trim($text);
        if ($text === '') {
            return '';
        }
        $pPr = '<w:pStyle w:val="' . $style . '"/>';
        if ($fill) {
            $pPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>';
            $pPr .= '<w:spacing w:before="80" w:after="80"/>';
        }
        if ($align) {
            $pPr .= '<w:jc w:val="' . $align . '"/>';
        }
        if ($keepNext) {
            $pPr .= '<w:keepNext/>';
        }
        if ($pageBreakBefore) {
            $pPr .= '<w:pageBreakBefore/>';
        }
        $rPr = ($bold ? '<w:b/>' : '') . ($color ? '<w:color w:val="' . $color . '"/>' : '');

        return '<w:p><w:pPr>' . $pPr . '</w:pPr><w:r><w:rPr>' . $rPr . '</w:rPr>'
            . $this->textRuns($text)
            . '</w:r></w:p>';
    }

    private function textRuns(string $text): string
    {
        $parts = preg_split('/\R/u', $text) ?: [$text];
        $xml = '';
        foreach ($parts as $index => $part) {
            if ($index > 0) {
                $xml .= '<w:br/>';
            }
            $xml .= '<w:t xml:space="preserve">' . $this->xml((string) $part) . '</w:t>';
        }
        return $xml;
    }

    /** @param array<int,mixed> $rows */
    private function metaTable(array $rows): string
    {
        $xml = $this->tableStart([1800, 3600, 1800, 3600]);
        foreach ($rows as $row) {
            $cells = is_array($row) ? array_values($row) : [];
            while (count($cells) < 4) {
                $cells[] = '';
            }
            $xml .= $this->rowStart(false)
                . $this->cell((string) $cells[0], 1800, 'EEF2F7', true, '1F4E78')
                . $this->cell((string) $cells[1], 3600)
                . $this->cell((string) $cells[2], 1800, 'EEF2F7', true, '1F4E78')
                . $this->cell((string) $cells[3], 3600)
                . '</w:tr>';
        }
        return $xml . '</w:tbl>';
    }

    /** @param array<int,mixed> $rows */
    private function twoColumnTable(array $rows, string $title = ''): string
    {
        $out = $title !== '' ? $this->paragraph($title, 'Moment', true, null, '1F4E78', null, true) : '';
        $out .= $this->tableStart([2400, 8400]);
        foreach ($rows as $row) {
            $cells = is_array($row) ? array_values($row) : [];
            if (count($cells) < 2) {
                continue;
            }
            $out .= $this->rowStart(false)
                . $this->cell((string) $cells[0], 2400, 'F3F4F6', true, '374151')
                . $this->cell((string) $cells[1], 8400)
                . '</w:tr>';
        }
        return $out . '</w:tbl>';
    }

    /** @param array<int,mixed> $headers @param array<int,mixed> $rows */
    private function dataTable(array $headers, array $rows): string
    {
        $count = max(1, count($headers));
        $widths = $this->tableWidths($headers);
        $out = $this->tableStart($widths);
        if ($headers !== []) {
            $out .= $this->rowStart(true);
            foreach ($headers as $index => $header) {
                $out .= $this->cell((string) $header, $widths[$index] ?? (int) floor(10800 / $count), '1F4E78', true, 'FFFFFF');
            }
            $out .= '</w:tr>';
        }
        foreach ($rows as $row) {
            $cells = is_array($row) ? array_values($row) : [];
            $out .= $this->rowStart(false);
            for ($i = 0; $i < $count; $i++) {
                $out .= $this->cell((string) ($cells[$i] ?? ''), $widths[$i] ?? (int) floor(10800 / $count));
            }
            $out .= '</w:tr>';
        }
        return $out . '</w:tbl>';
    }

    /** @param array<int,mixed> $headers @return list<int> */
    private function tableWidths(array $headers): array
    {
        $normalized = array_map(static fn (mixed $header): string => trim((string) $header), $headers);
        if ($normalized === ['Fecha', 'Sesión', 'Objetivo', 'Actividad central', 'Evidencia']) {
            return [1350, 2050, 2500, 3250, 1650];
        }
        if ($normalized !== [] && $normalized[0] === 'Criterio') {
            $rest = max(1, count($normalized) - 1);
            $criterion = count($normalized) >= 4 ? 5400 : 6500;
            $remaining = 10800 - $criterion;
            $column = (int) floor($remaining / $rest);
            return array_merge([$criterion], array_fill(0, $rest, $column));
        }

        $count = max(1, count($normalized));
        $width = (int) floor(10800 / $count);
        return array_fill(0, $count, $width);
    }

    private function callout(string $label, string $text): string
    {
        return $this->tableStart([10800])
            . $this->rowStart(false) . $this->cell(trim($label . "\n" . $text), 10800, 'F7FAFC', false, '111827') . '</w:tr>'
            . '</w:tbl>';
    }

    private function keyValue(string $label, string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        return $this->paragraph($label . ': ' . $text, 'Normal', false);
    }

    private function smallNote(string $label, string $text): string
    {
        if (trim($text) === '') {
            return '';
        }
        return $this->paragraph($label . ': ' . $text, 'Small', false, 'F9FAFB', '4B5563');
    }

    /** @param array<int,mixed> $items */
    private function listBlock(string $label, array $items, bool $checklist): string
    {
        $out = $label !== '' ? $this->paragraph($label, 'Moment', true, null, '1F4E78', null, true) : '';
        foreach ($items as $item) {
            $text = trim((string) $item);
            if ($text === '') {
                continue;
            }
            $out .= $this->paragraph(($checklist ? '☐ ' : '• ') . $text, 'Normal');
        }
        return $out;
    }

    private function sessionHeader(string $text, string $meta): string
    {
        $out = $this->paragraph($text, 'Session', true, 'DCE6F1', '17365D', null, true);
        if (trim($meta) !== '') {
            $out .= $this->paragraph($meta, 'Small', false, null, '4B5563', null, true);
        }
        return $out;
    }

    private function momentHeader(string $text, string $meta): string
    {
        $label = strtoupper(trim($text));
        if (trim($meta) !== '') {
            $label .= ' · ' . trim($meta);
        }
        return $this->paragraph($label, 'Moment', true, 'EEF2F7', '1F4E78', null, true);
    }

    /** @param array<string,mixed> $block */
    private function activityBlock(array $block): string
    {
        $instruction = trim((string) ($block['instruction'] ?? 'Actividad'));
        $teacher = trim((string) ($block['teacher_action'] ?? '—'));
        $student = trim((string) ($block['student_action'] ?? '—'));
        $organization = trim((string) ($block['organization'] ?? ''));
        $materials = $this->stringList($block['materials'] ?? []);
        $evidence = $this->stringList($block['evidence'] ?? []);
        $checks = $this->stringList($block['checks'] ?? []);

        $out = $this->tableStart([5400, 5400]);
        $out .= $this->rowStart(false) . $this->cell($instruction, 10800, 'F8FAFC', true, '111827', 2) . '</w:tr>';
        $out .= $this->rowStart(false)
            . $this->cell("DOCENTE\n" . $teacher, 5400, 'FFFFFF', false)
            . $this->cell("ALUMNOS\n" . $student, 5400, 'FFFFFF', false)
            . '</w:tr>';
        $detailLines = [];
        if ($organization !== '') {
            $detailLines[] = 'Organización: ' . $organization;
        }
        if ($materials !== []) {
            $detailLines[] = 'Materiales: ' . implode(' · ', $materials);
        }
        if ($evidence !== []) {
            $detailLines[] = 'Evidencia: ' . implode(' · ', $evidence);
        }
        if ($checks !== []) {
            $detailLines[] = 'Observar: ' . implode(' · ', $checks);
        }
        if ($detailLines !== []) {
            $out .= $this->rowStart(false) . $this->cell(implode("\n", $detailLines), 10800, 'F3F4F6', false, '4B5563', 2) . '</w:tr>';
        }
        return $out . '</w:tbl>';
    }

    /** @param array<string,mixed> $block */
    private function instrumentBlock(array $block): string
    {
        $name = trim((string) ($block['name'] ?? 'Instrumento'));
        $purpose = trim((string) ($block['purpose'] ?? ''));
        $criteria = $this->stringList($block['criteria'] ?? []);
        $scale = $this->stringList($block['scale'] ?? []);
        $sessions = $this->stringList($block['sessions'] ?? []);

        $out = $this->paragraph($name, 'Moment', true, 'E8EEF5', '1F4E78', null, true);
        if ($purpose !== '') {
            $out .= $this->paragraph($purpose, 'Normal');
        }
        if ($sessions !== []) {
            $out .= $this->smallNote('Aplica a', implode(', ', $sessions));
        }
        if ($criteria !== []) {
            $headers = ['Criterio'];
            if ($scale !== []) {
                $headers = array_merge($headers, $scale);
            } else {
                $headers[] = 'Registro';
            }
            $rows = [];
            foreach ($criteria as $criterion) {
                $row = [$criterion];
                $markColumns = count($headers) - 1;
                for ($i = 0; $i < $markColumns; $i++) {
                    $row[] = '☐';
                }
                $rows[] = $row;
            }
            $out .= $this->dataTable($headers, $rows);
        }
        return $out;
    }

    /** @param list<int> $widths */
    private function tableStart(array $widths): string
    {
        $grid = '';
        foreach ($widths as $width) {
            $grid .= '<w:gridCol w:w="' . (int) $width . '"/>';
        }
        return '<w:tbl><w:tblPr><w:tblW w:w="10800" w:type="dxa"/><w:tblLayout w:type="fixed"/>'
            . '<w:tblBorders>'
            . '<w:top w:val="single" w:sz="4" w:color="D1D5DB"/>'
            . '<w:left w:val="single" w:sz="4" w:color="D1D5DB"/>'
            . '<w:bottom w:val="single" w:sz="4" w:color="D1D5DB"/>'
            . '<w:right w:val="single" w:sz="4" w:color="D1D5DB"/>'
            . '<w:insideH w:val="single" w:sz="4" w:color="E5E7EB"/>'
            . '<w:insideV w:val="single" w:sz="4" w:color="E5E7EB"/>'
            . '</w:tblBorders>'
            . '<w:tblCellMar><w:top w:w="90" w:type="dxa"/><w:left w:w="110" w:type="dxa"/><w:bottom w:w="90" w:type="dxa"/><w:right w:w="110" w:type="dxa"/></w:tblCellMar>'
            . '</w:tblPr><w:tblGrid>' . $grid . '</w:tblGrid>';
    }

    private function rowStart(bool $header): string
    {
        return '<w:tr><w:trPr>' . ($header ? '<w:tblHeader/>' : '') . '<w:cantSplit/></w:trPr>';
    }

    private function cell(
        string $text,
        int $width,
        ?string $fill = null,
        bool $bold = false,
        ?string $color = null,
        int $gridSpan = 1,
    ): string {
        $tcPr = '<w:tcW w:w="' . $width . '" w:type="dxa"/><w:vAlign w:val="top"/>';
        if ($fill) {
            $tcPr .= '<w:shd w:val="clear" w:color="auto" w:fill="' . $fill . '"/>';
        }
        if ($gridSpan > 1) {
            $tcPr .= '<w:gridSpan w:val="' . $gridSpan . '"/>';
        }
        $rPr = ($bold ? '<w:b/>' : '') . ($color ? '<w:color w:val="' . $color . '"/>' : '');
        return '<w:tc><w:tcPr>' . $tcPr . '</w:tcPr><w:p><w:pPr><w:spacing w:after="40"/></w:pPr><w:r><w:rPr>' . $rPr . '</w:rPr>'
            . $this->textRuns($text)
            . '</w:r></w:p></w:tc>';
    }

    /** @param mixed $value @return list<string> */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => is_scalar($item) ? trim((string) $item) : '',
            $value,
        ), static fn (string $item): bool => $item !== ''));
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
