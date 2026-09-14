@php
    $viewData = [
        'format' => $format,
        'version' => $version,
        'analysis' => $analysis,
        'zones' => $zones,
        'structuralZones' => $structuralZones,
        'anchors' => $anchors,
        'mapping' => $mapping,
        'fields' => $fields,
        'sourceUrl' => $sourceUrl,
        'bindingUrl' => $bindingUrl,
        'structureBindingUrl' => $structureBindingUrl,
        'previewUrl' => $previewUrl,
        'backUrl' => $backUrl,
    ];

    // `designer-structures` conserva por compatibilidad la composición histórica
    // (documento base completo + extensión estructural). Para entregar HTML válido,
    // aislamos la extensión y la insertamos antes del cierre de <body> del diseñador.
    $baseHtml = view('institutional-formats.designer', $viewData)->render();
    $legacyComposedHtml = view('institutional-formats.designer-structures', $viewData)->render();
    $htmlEnd = strripos($legacyComposedHtml, '</html>');
    $extensionHtml = $htmlEnd === false
        ? ''
        : substr($legacyComposedHtml, $htmlEnd + strlen('</html>'));
    $bodyEnd = strripos($baseHtml, '</body>');

    if ($bodyEnd === false || trim($extensionHtml) === '') {
        echo $baseHtml;
    } else {
        echo substr($baseHtml, 0, $bodyEnd)
            . $extensionHtml
            . substr($baseHtml, $bodyEnd);
    }
@endphp
