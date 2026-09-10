<?php

namespace App\Services\Documents;

final class StandardDocxRenderer
{
    /** @param list<array{type:string,text:string}> $blocks */
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
            . '<Application>Planeaciones</Application><AppVersion>1.0</AppVersion>'
            . '</Properties>';
    }

    private function styles(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:styles xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main">'
            . '<w:docDefaults><w:rPrDefault><w:rPr><w:rFonts w:ascii="Aptos" w:hAnsi="Aptos"/><w:sz w:val="22"/><w:lang w:val="es-MX"/></w:rPr></w:rPrDefault></w:docDefaults>'
            . $this->styleXml('Normal', 'Normal', 22, false, 0, 120)
            . $this->styleXml('Title', 'Título', 36, true, 0, 240)
            . $this->styleXml('Heading1', 'Título 1', 28, true, 240, 120)
            . $this->styleXml('Heading2', 'Título 2', 24, true, 180, 90)
            . $this->styleXml('Heading3', 'Título 3', 22, true, 120, 60)
            . '</w:styles>';
    }

    private function styleXml(string $id, string $name, int $size, bool $bold, int $before, int $after): string
    {
        return '<w:style w:type="paragraph" w:styleId="' . $id . '">'
            . '<w:name w:val="' . $this->xml($name) . '"/>'
            . '<w:pPr><w:spacing w:before="' . $before . '" w:after="' . $after . '" w:line="276" w:lineRule="auto"/></w:pPr>'
            . '<w:rPr>' . ($bold ? '<w:b/>' : '') . '<w:sz w:val="' . $size . '"/></w:rPr>'
            . '</w:style>';
    }

    /** @param list<array{type:string,text:string}> $blocks */
    private function documentXml(array $blocks): string
    {
        $body = '';
        foreach ($blocks as $block) {
            $type = $block['type'];
            $text = $block['text'];
            $style = match ($type) {
                'title' => 'Title',
                'heading1' => 'Heading1',
                'heading2' => 'Heading2',
                'heading3' => 'Heading3',
                default => 'Normal',
            };
            $prefix = $type === 'bullet' ? '• ' : '';
            $body .= '<w:p><w:pPr><w:pStyle w:val="' . $style . '"/>';
            if ($type === 'bullet') {
                $body .= '<w:ind w:left="360" w:hanging="180"/>';
            }
            $body .= '</w:pPr><w:r><w:t xml:space="preserve">' . $this->xml($prefix . $text) . '</w:t></w:r></w:p>';
        }

        $body .= '<w:sectPr><w:pgSz w:w="12240" w:h="15840"/><w:pgMar w:top="1080" w:right="1080" w:bottom="1080" w:left="1080" w:header="720" w:footer="720"/></w:sectPr>';

        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'
            . $body
            . '</w:body></w:document>';
    }

    private function xml(string $value): string
    {
        return htmlspecialchars($value, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
