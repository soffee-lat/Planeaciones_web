<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Diseñador visual · {{ $format->name }}</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;background:#f1f5f9}
        *{box-sizing:border-box}body{margin:0}.topbar{min-height:64px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;gap:12px;padding:10px 20px;position:sticky;top:0;z-index:50}.topbar h1{font-size:16px;margin:0}.topbar small{color:#64748b}.top-actions,.view-toggle{display:flex;gap:8px;flex-wrap:wrap;align-items:center}.btn{border:0;border-radius:9px;padding:9px 13px;font-weight:650;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center;gap:7px}.btn:disabled{opacity:.55;cursor:not-allowed}.btn-primary{background:#0f766e;color:#fff}.btn-secondary{background:#e2e8f0;color:#0f172a}.btn-danger{background:#fee2e2;color:#991b1b}.btn-blue{background:#dbeafe;color:#1d4ed8}.btn-ghost{background:#fff;border:1px solid #cbd5e1;color:#334155}.btn-toggle.active{background:#0f172a;color:#fff}.layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;min-height:calc(100vh - 64px)}.canvas{padding:20px;overflow:auto}.side{background:#fff;border-left:1px solid #e2e8f0;padding:18px;overflow:auto;position:sticky;top:64px;height:calc(100vh - 64px)}.notice{background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:12px 14px;margin-bottom:14px;color:#155e75;font-size:13px;line-height:1.5}.notice.warning{background:#fff7ed;border-color:#fed7aa;color:#9a3412}.legend{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 14px}.legend span{font-size:12px;padding:5px 8px;border-radius:999px;border:1px solid}.l-green{background:#dcfce7;border-color:#86efac!important}.l-yellow{background:#fef9c3;border-color:#fde047!important}.l-blue{background:#dbeafe;border-color:#93c5fd!important}.l-gray{background:#f1f5f9;border-color:#cbd5e1!important}.viewer-shell{background:#cbd5e1;border-radius:14px;padding:18px;min-height:70vh;overflow:auto}.viewer-shell.loading{display:grid;place-items:center;color:#475569}.docx-wrapper{margin:0 auto}.template-zone{transition:box-shadow .15s,background .15s;position:relative}.fields-mode .template-zone{cursor:pointer}.fields-mode .template-zone:hover{box-shadow:inset 0 0 0 2px #0f766e!important}.fields-mode .zone-bound{box-shadow:inset 0 0 0 2px #22c55e;background:rgba(34,197,94,.08)!important}.fields-mode .zone-custom{box-shadow:inset 0 0 0 2px #3b82f6;background:rgba(59,130,246,.08)!important}.fields-mode .zone-ignored{box-shadow:inset 0 0 0 1px #94a3b8;background:rgba(148,163,184,.06)!important}.fields-mode .zone-unmapped{box-shadow:inset 0 0 0 2px #eab308;background:rgba(234,179,8,.08)!important}.fields-mode .zone-selected{box-shadow:inset 0 0 0 3px #0f766e!important}.fields-mode .template-zone[data-field-count]:after{content:attr(data-field-count);position:absolute;right:2px;top:2px;background:#0f172a;color:#fff;font-size:9px;line-height:1;border-radius:999px;padding:3px 5px;z-index:5}.original-mode .template-zone{box-shadow:none!important;background:transparent!important;cursor:text!important}.card{border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:13px}.card h2{font-size:15px;margin:0 0 8px}.muted{color:#64748b;font-size:13px;line-height:1.45}.zone-title{font-weight:750;font-size:14px}.excerpt{background:#f8fafc;border-radius:8px;padding:9px;margin:7px 0 10px;font-size:12px;max-height:110px;overflow:auto;white-space:pre-wrap}.fixed-label{font-size:12px;background:#f1f5f9;border-radius:8px;padding:8px;margin:7px 0}.concept-preview{font-size:13px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:8px;padding:9px;margin-top:10px}.field-row{display:flex;gap:8px;margin-top:10px}.field-row>*{flex:1}select,input,textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:9px;background:#fff;color:#0f172a}textarea{min-height:78px;resize:vertical}label{font-size:12px;font-weight:700;display:block;margin:10px 0 5px}.hidden{display:none!important}.status{font-size:11px;border-radius:999px;padding:4px 8px;background:#f1f5f9;white-space:nowrap}.configured-list{display:grid;gap:7px}.configured-item{font-size:12px;padding:9px;border:1px solid #e2e8f0;border-radius:8px;line-height:1.35;background:#fff;cursor:pointer;text-align:left}.configured-item:hover{border-color:#0f766e}.toast{position:fixed;right:22px;bottom:22px;background:#0f172a;color:#fff;padding:11px 15px;border-radius:10px;z-index:99;box-shadow:0 10px 30px #0003}.error{background:#991b1b}.spinner{width:26px;height:26px;border:3px solid #cbd5e1;border-top-color:#0f766e;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}.selection-warning{background:#fef3c7;border:1px solid #fcd34d;border-radius:9px;padding:10px;font-size:12px;line-height:1.45;margin:8px 0}.helper-example{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;background:#f8fafc;border-radius:7px;padding:8px;font-size:12px;margin-top:7px}.custom-trigger{width:100%;justify-content:center}.counter{display:flex;gap:8px;margin-bottom:10px}.counter span{font-size:11px;background:#f8fafc;border:1px solid #e2e8f0;border-radius:999px;padding:4px 7px}
        @media(max-width:1000px){.layout{grid-template-columns:1fr}.side{position:relative;top:auto;height:auto;border-left:0;border-top:1px solid #e2e8f0}.canvas{padding:10px}.viewer-shell{padding:8px}.topbar{align-items:flex-start}.top-actions{max-width:58%;justify-content:flex-end}}
    </style>
</head>
<body class="fields-mode">
<header class="topbar">
    <div><h1>Diseñador visual de formato</h1><small>{{ $format->name }} · v{{ $version->number }}</small></div>
    <div class="top-actions">
        <div class="view-toggle"><button id="original-mode" class="btn btn-toggle btn-ghost">Original</button><button id="fields-mode" class="btn btn-toggle active">Campos</button></div>
        <button id="preview-btn" class="btn btn-primary">Generar y ver ejemplo</button>
        <a class="btn btn-secondary" href="{{ $backUrl }}">Volver al resumen</a>
    </div>
</header>
<div class="layout">
    <main class="canvas">
        <div class="notice"><strong>Marca el contenido que cambia, no el nombre del campo.</strong> Ejemplo: en <b>Fecha: 11/09/2026</b>, selecciona <b>11/09/2026</b>. La palabra “Fecha:” permanece fija y solo ese valor se reemplaza en cada planeación.</div>
        <div class="notice warning"><strong>Si una línea tiene varios datos</strong> —por ejemplo “GRADO: 3° · GRUPO: A”— arrastra con el mouse sobre cada valor por separado. Así cada variable conserva su etiqueta y no se reemplaza toda la línea.</div>
        <div class="legend"><span class="l-green">Verde · configurado</span><span class="l-yellow">Amarillo · necesita revisión</span><span class="l-blue">Azul · personalizado</span><span class="l-gray">Gris · no modificar</span></div>
        <div id="viewer" class="viewer-shell loading"><div><div class="spinner" style="margin:auto"></div><p>Preparando tu documento…</p></div></div>
    </main>
    <aside class="side">
        <div class="counter"><span id="configured-count">0 configurados</span><span id="fragment-count">0 valores precisos</span></div>
        <div id="empty-state" class="card">
            <h2>Selecciona el valor que cambia</h2>
            <div class="muted">Puedes arrastrar sobre texto como una fecha, grado o nombre. Si una celda completa es el espacio del campo, basta con hacer clic en ella.</div>
            <div class="helper-example">Fecha: <strong>[11/09/2026]</strong><br>GRADO: <strong>[3°]</strong> &nbsp; GRUPO: <strong>[A]</strong></div>
        </div>
        <div id="label-warning" class="card hidden">
            <h2>Seleccionaste una etiqueta</h2>
            <div class="selection-warning">La etiqueta se conserva. Debes marcar el dato que está después de ella, porque ese es el contenido que cambiará en cada planeación.</div>
            <button id="use-suggested-value" class="btn btn-primary hidden" style="width:100%">Usar el valor que está después</button>
        </div>
        <div id="editor" class="hidden">
            <div class="card">
                <div style="display:flex;justify-content:space-between;gap:10px"><div class="zone-title" id="zone-name">Valor seleccionado</div><span class="status" id="zone-status">Sin configurar</span></div>
                <div id="fixed-label-row" class="fixed-label hidden"><strong>Se conserva:</strong> <span id="fixed-label"></span></div>
                <label>Contenido que se reemplazará</label>
                <div class="excerpt" id="zone-excerpt">—</div>
                <div id="multi-warning" class="selection-warning hidden"><strong>Esta línea contiene varios datos.</strong> Selecciona con el mouse únicamente uno de los valores antes de guardarlo.</div>
                <label for="field-select">¿Qué información irá en este espacio?</label>
                <select id="field-select"><option value="">Selecciona un campo…</option></select>
                <div id="concept-preview" class="concept-preview">Vista previa: [Campo]</div>
                <div class="field-row"><button id="bind-btn" class="btn btn-primary">Confirmar campo</button><button id="ignore-btn" class="btn btn-secondary">No modificar</button></div>
                <button id="unbind-btn" class="btn btn-danger hidden" style="margin-top:8px;width:100%">Quitar relación</button>
            </div>
            <div class="card">
                <button id="custom-toggle" class="btn btn-blue custom-trigger">+ Crear un campo que no aparece en la lista</button>
                <div id="custom-panel" class="hidden">
                    <div class="muted" style="margin-top:10px">Úsalo cuando el formato de tu escuela pide un dato especial. En esta fase queda guardado en el formato y se puede validar con la muestra.</div>
                    <label>Nombre del campo</label><input id="custom-label" placeholder="Ej. Producto integrador">
                    <label>Tipo de información</label><select id="custom-type"><option value="text">Texto corto</option><option value="long_text" selected>Texto largo</option><option value="date">Fecha</option><option value="list">Lista</option><option value="table">Tabla</option><option value="repeating_block">Bloque repetible</option></select>
                    <label>Indicaciones</label><textarea id="custom-instruction" placeholder="Ej. Describe el producto final que elaborarán los estudiantes."></textarea>
                    <button id="custom-btn" class="btn btn-blue" style="margin-top:9px;width:100%">Crear y usar este campo</button>
                </div>
            </div>
        </div>
        <div class="card"><h2>Campos configurados</h2><div class="muted" style="margin-bottom:8px">Haz clic en uno para revisarlo o cambiarlo.</div><div id="configured-list" class="configured-list"></div></div>
    </aside>
</div>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.4.0/dist/docx-preview.min.js"></script>
<script>
(() => {
    const sourceUrl = @json($sourceUrl);
    const bindingUrl = @json($bindingUrl);
    const previewUrl = @json($previewUrl);
    const initialZones = @json($zones);
    const anchors = @json($anchors);
    const standardFields = @json($fields);
    let mapping = @json($mapping);
    mapping.anchors = mapping.anchors || {};
    mapping.fragments = mapping.fragments || {};
    mapping.custom_fields = mapping.custom_fields || {};
    mapping.ignored_zones = mapping.ignored_zones || [];

    const zoneMap = new Map(initialZones.map(z => [z.id, z]));
    const viewer = document.getElementById('viewer');
    const editor = document.getElementById('editor');
    const empty = document.getElementById('empty-state');
    const labelWarning = document.getElementById('label-warning');
    const fieldSelect = document.getElementById('field-select');
    const previewBtn = document.getElementById('preview-btn');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    let viewMode = 'fields';
    let selectionState = null;
    let suggestedValueState = null;
    let selectedElement = null;

    function fields() {
        const result = {...standardFields};
        Object.entries(mapping.custom_fields || {}).forEach(([key, def]) => result['custom.' + key] = 'Personalizado · ' + (def.label || key));
        return result;
    }

    function normalize(value) {
        return String(value || '').trim().toLocaleLowerCase('es').replace(/[:.]+$/,'').replace(/\s+/g,' ');
    }

    function refreshFieldOptions() {
        const current = fieldSelect.value;
        fieldSelect.innerHTML = '<option value="">Selecciona un campo…</option>';
        Object.entries(fields()).forEach(([path,label]) => {
            const option = document.createElement('option');
            option.value = path;
            option.textContent = label;
            fieldSelect.appendChild(option);
        });
        if (current && fields()[current]) fieldSelect.value = current;
    }

    function anchorForTarget(id) {
        return anchors.find(a => a && a.target_id === id) || anchors.find(a => a && a.id === id) || null;
    }

    function wholeBindingKey(id) {
        if (mapping.anchors[id]) return id;
        const anchor = anchorForTarget(id);
        if (anchor && mapping.anchors[anchor.id]) return anchor.id;
        return id;
    }

    function wholePathFor(id) {
        return mapping.anchors[wholeBindingKey(id)] || null;
    }

    function fragmentsForZone(id) {
        return Object.entries(mapping.fragments || {}).filter(([, fragment]) => fragment && fragment.zone_id === id);
    }

    function isIgnored(id) {
        return (mapping.ignored_zones || []).includes(id) || (mapping.ignored_zones || []).includes(wholeBindingKey(id));
    }

    function zoneText(id, element = null) {
        return element ? String(element.textContent || '') : String(zoneMap.get(id)?.text_excerpt || '');
    }

    function multipleLabels(text) {
        const matches = String(text || '').match(/[A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ0-9 .\/()_-]{1,45}:\s*/g);
        return (matches || []).length > 1;
    }

    function isSuggested(id, element = null) {
        const anchor = anchorForTarget(id);
        if (multipleLabels(zoneText(id, element)) && fragmentsForZone(id).length === 0) return true;
        return !!(anchor && anchor.suggested_path && !wholePathFor(id) && fragmentsForZone(id).length === 0 && !isIgnored(id));
    }

    function displayNameForZone(id) {
        const zone = zoneMap.get(id) || {};
        if (zone.text_excerpt) return '“' + String(zone.text_excerpt).slice(0, 55) + (String(zone.text_excerpt).length > 55 ? '…' : '') + '”';
        return zone.kind === 'cell' ? 'Celda vacía' : 'Párrafo vacío';
    }

    function decorate(element, id) {
        element.classList.add('template-zone');
        element.classList.remove('zone-bound','zone-custom','zone-ignored','zone-unmapped','zone-selected');
        element.removeAttribute('data-field-count');
        if (viewMode === 'original') return;

        const fragments = fragmentsForZone(id);
        const wholePath = wholePathFor(id);
        if (isIgnored(id)) element.classList.add('zone-ignored');
        else if (fragments.some(([, f]) => String(f.field_path || '').startsWith('custom.'))) element.classList.add('zone-custom');
        else if (fragments.length > 0 || wholePath) element.classList.add('zone-bound');
        else if (isSuggested(id, element)) element.classList.add('zone-unmapped');
        if (selectionState && selectionState.zoneId === id) element.classList.add('zone-selected');
        const count = fragments.length || (wholePath ? 1 : 0);
        if (count) element.dataset.fieldCount = count === 1 ? '1 campo' : count + ' campos';
    }

    function attachZone(element, id) {
        element.dataset.zoneId = id;
        decorate(element, id);
        if (element.dataset.designerBound === '1') return;
        element.dataset.designerBound = '1';
        element.addEventListener('click', event => {
            if (viewMode !== 'fields') return;
            if (String(window.getSelection()?.toString() || '').trim() !== '') return;
            event.preventDefault();
            event.stopPropagation();
            selectWholeZone(id, element);
        });
    }

    function annotate() {
        viewer.querySelectorAll('td').forEach((element, index) => {
            const id = 'c:' + index;
            if (zoneMap.has(id)) attachZone(element, id);
        });
        viewer.querySelectorAll('p').forEach((element, index) => {
            const id = 'p:' + index;
            if (zoneMap.has(id) && !element.closest('td')) attachZone(element, id);
        });
        viewer.querySelectorAll('[data-zone-id]').forEach(el => decorate(el, el.dataset.zoneId));
        refreshConfigured();
    }

    function elementForZone(id) {
        return Array.from(viewer.querySelectorAll('[data-zone-id]')).find(el => el.dataset.zoneId === id) || null;
    }

    function pointOffset(root, node, offset) {
        const range = document.createRange();
        range.selectNodeContents(root);
        try { range.setEnd(node, offset); } catch (_) { return 0; }
        return Array.from(range.cloneContents().textContent || '').length;
    }

    function inferLabelHint(fullText, start, anchor = null) {
        if (anchor && anchor.label) {
            const current = String(anchor.current_value_excerpt || '');
            const selected = Array.from(fullText).slice(start).join('');
            if (current && selected.includes(current)) return String(anchor.label).replace(/:$/,'').trim();
        }
        const prefix = Array.from(fullText).slice(0, start).join('');
        const uppercase = prefix.match(/([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ0-9 .\/()_-]{0,50}):\s*$/u);
        if (uppercase) return uppercase[1].trim();
        const simple = prefix.match(/([A-ZÁÉÍÓÚÑ][\p{L}0-9 _\/-]{0,28}):\s*$/u);
        return simple ? simple[1].trim() : '';
    }

    function looksLikeSelectedLabel(text, id) {
        const value = normalize(text);
        if (!value) return false;
        if (String(text).trim().endsWith(':')) return true;
        return anchors.some(a => a && normalize(a.label) === value && (a.id === id || a.target_id === id));
    }

    function proposeValueAfterLabel(id, element, start, end) {
        const chars = Array.from(String(element.textContent || ''));
        let colon = end;
        while (colon < Math.min(chars.length, end + 4) && chars[colon] !== ':') colon++;
        if (colon >= chars.length || chars[colon] !== ':') {
            const selected = chars.slice(start, end).join('');
            if (!selected.trim().endsWith(':')) return null;
            colon = end - 1;
        }
        let valueStart = colon + 1;
        while (valueStart < chars.length && /\s/u.test(chars[valueStart])) valueStart++;
        if (valueStart >= chars.length) return null;
        const remaining = chars.slice(valueStart).join('');
        const next = remaining.match(/\s{2,}([A-ZÁÉÍÓÚÑ][A-ZÁÉÍÓÚÑ0-9 .\/()_-]{1,45}):\s*/u);
        let valueEnd = next ? valueStart + Array.from(remaining.slice(0, next.index)).length : chars.length;
        while (valueEnd > valueStart && /\s/u.test(chars[valueEnd - 1])) valueEnd--;
        if (valueEnd <= valueStart) return null;
        return {
            kind:'fragment', zoneId:id, element, start:valueStart, end:valueEnd,
            text:chars.slice(valueStart,valueEnd).join(''),
            labelHint:chars.slice(start, colon).join('').replace(/:$/,'').trim(), bindingId:null, fieldPath:null,
        };
    }

    function handleTextSelection() {
        if (viewMode !== 'fields') return;
        const selection = window.getSelection();
        if (!selection || selection.isCollapsed || !String(selection.toString()).trim()) return;
        const range = selection.getRangeAt(0);
        const startEl = range.startContainer.nodeType === Node.ELEMENT_NODE ? range.startContainer : range.startContainer.parentElement;
        const endEl = range.endContainer.nodeType === Node.ELEMENT_NODE ? range.endContainer : range.endContainer.parentElement;
        const zoneElement = startEl?.closest?.('[data-zone-id]');
        if (!zoneElement || zoneElement !== endEl?.closest?.('[data-zone-id]')) return;
        const id = zoneElement.dataset.zoneId;
        const start = pointOffset(zoneElement, range.startContainer, range.startOffset);
        const end = pointOffset(zoneElement, range.endContainer, range.endOffset);
        if (end <= start) return;
        const fullText = String(zoneElement.textContent || '');
        const text = Array.from(fullText).slice(start,end).join('');
        const anchor = anchorForTarget(id);

        if (looksLikeSelectedLabel(text, id)) {
            suggestedValueState = proposeValueAfterLabel(id, zoneElement, start, end);
            selection.removeAllRanges();
            showLabelWarning();
            return;
        }

        selection.removeAllRanges();
        openSelection({kind:'fragment',zoneId:id,element:zoneElement,start,end,text,labelHint:inferLabelHint(fullText,start,anchor),bindingId:null,fieldPath:null});
    }

    function showLabelWarning() {
        selectionState = null;
        editor.classList.add('hidden');
        empty.classList.add('hidden');
        labelWarning.classList.remove('hidden');
        document.getElementById('use-suggested-value').classList.toggle('hidden', !suggestedValueState);
    }

    function selectWholeZone(id, element) {
        const text = String(element.textContent || '').trim();
        const anchor = anchorForTarget(id);
        if (multipleLabels(text) && fragmentsForZone(id).length === 0) {
            openSelection({kind:'zone',zoneId:id,element,text,labelHint:'',bindingId:null,fieldPath:wholePathFor(id),requiresPrecise:true});
            return;
        }
        if (anchor && anchor.replacement_mode === 'replace_after_label' && anchor.current_value_excerpt && !multipleLabels(text)) {
            const raw = String(element.textContent || '');
            const value = String(anchor.current_value_excerpt);
            const codeUnitIndex = raw.indexOf(value);
            if (codeUnitIndex >= 0) {
                const start = Array.from(raw.slice(0, codeUnitIndex)).length;
                const end = start + Array.from(value).length;
                openSelection({kind:'fragment',zoneId:id,element,start,end,text:value,labelHint:String(anchor.label || ''),bindingId:null,fieldPath:wholePathFor(id) || anchor.suggested_path || null});
                return;
            }
        }
        openSelection({kind:'zone',zoneId:id,element,text:text || 'Zona vacía',labelHint:anchor?.label || '',bindingId:null,fieldPath:wholePathFor(id) || anchor?.suggested_path || null,requiresPrecise:false});
    }

    function openSelection(state) {
        if (selectedElement && selectionState) decorate(selectedElement, selectionState.zoneId);
        selectionState = state;
        suggestedValueState = null;
        selectedElement = state.element || elementForZone(state.zoneId);
        labelWarning.classList.add('hidden');
        empty.classList.add('hidden');
        editor.classList.remove('hidden');
        if (selectedElement) decorate(selectedElement, state.zoneId);

        const isFragment = state.kind === 'fragment';
        const path = state.fieldPath || (isFragment && state.bindingId ? mapping.fragments[state.bindingId]?.field_path : null) || (!isFragment ? wholePathFor(state.zoneId) : null);
        document.getElementById('zone-name').textContent = isFragment ? 'Valor seleccionado' : 'Zona seleccionada';
        document.getElementById('zone-status').textContent = path ? (String(path).startsWith('custom.') ? 'Personalizado' : 'Configurado') : (state.requiresPrecise ? 'Necesita precisión' : 'Sin configurar');
        document.getElementById('zone-excerpt').textContent = state.text || 'Zona vacía';
        document.getElementById('fixed-label-row').classList.toggle('hidden', !state.labelHint);
        document.getElementById('fixed-label').textContent = state.labelHint ? state.labelHint + ':' : '';
        document.getElementById('multi-warning').classList.toggle('hidden', !state.requiresPrecise);
        refreshFieldOptions();
        fieldSelect.value = path || '';
        document.getElementById('bind-btn').disabled = !!state.requiresPrecise;
        document.getElementById('ignore-btn').textContent = isFragment ? 'Cancelar selección' : 'No modificar';
        document.getElementById('unbind-btn').classList.toggle('hidden', !(state.bindingId || (!isFragment && (wholePathFor(state.zoneId) || isIgnored(state.zoneId)))));
        updateConceptPreview();
    }

    function updateConceptPreview() {
        if (!selectionState) return;
        const label = fields()[fieldSelect.value] || 'Campo';
        const fixed = selectionState.labelHint ? selectionState.labelHint.replace(/:$/,'') + ': ' : '';
        document.getElementById('concept-preview').textContent = 'Vista previa conceptual: ' + fixed + '[' + label + ']';
    }

    function payloadFor(mode, extra = {}) {
        if (!selectionState) return null;
        const payload = {zone_id:selectionState.zoneId, mode, ...extra};
        if (selectionState.bindingId) payload.binding_id = selectionState.bindingId;
        if (selectionState.kind === 'fragment') {
            payload.fragment_start = selectionState.start;
            payload.fragment_end = selectionState.end;
            payload.fragment_text = selectionState.text;
            payload.label_hint = selectionState.labelHint || '';
        }
        return payload;
    }

    async function save(payload) {
        const response = await fetch(bindingUrl, {method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(payload)});
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'No se pudo guardar la configuración.');
        mapping = data.mapping || mapping;
        clearSelection(false);
        annotate();
        toast('Cambio guardado');
    }

    function clearSelection(showEmpty = true) {
        if (selectedElement && selectionState) decorate(selectedElement, selectionState.zoneId);
        selectionState = null; selectedElement = null; suggestedValueState = null;
        editor.classList.add('hidden'); labelWarning.classList.add('hidden');
        empty.classList.toggle('hidden', !showEmpty);
    }

    function toast(message, error = false) {
        const element = document.createElement('div'); element.className='toast'+(error?' error':''); element.textContent=message; document.body.appendChild(element); setTimeout(()=>element.remove(),2800);
    }

    function refreshConfigured() {
        const list = document.getElementById('configured-list'); list.innerHTML='';
        const wholeEntries = Object.entries(mapping.anchors || {});
        const fragmentEntries = Object.entries(mapping.fragments || {});
        document.getElementById('configured-count').textContent = (wholeEntries.length + fragmentEntries.length) + ' configurados';
        document.getElementById('fragment-count').textContent = fragmentEntries.length + ' valores precisos';
        if (!wholeEntries.length && !fragmentEntries.length) { list.innerHTML='<div class="muted">Todavía no hay campos configurados.</div>'; return; }

        fragmentEntries.forEach(([id,fragment]) => {
            const item=document.createElement('button'); item.type='button'; item.className='configured-item';
            const label=fragment.label_hint ? fragment.label_hint+': ' : '';
            item.textContent=label+'“'+String(fragment.source_text || '').slice(0,45)+'” → '+(fields()[fragment.field_path] || fragment.field_path);
            item.addEventListener('click',()=>{
                const el=elementForZone(fragment.zone_id);
                openSelection({kind:'fragment',zoneId:fragment.zone_id,element:el,start:Number(fragment.start),end:Number(fragment.end),text:fragment.source_text,labelHint:fragment.label_hint || '',bindingId:id,fieldPath:fragment.field_path});
                el?.scrollIntoView({behavior:'smooth',block:'center'});
            });
            list.appendChild(item);
        });

        wholeEntries.forEach(([zoneId,path]) => {
            const anchor=anchors.find(a=>a&&a.id===zoneId); const targetId=anchor?.target_id || zoneId;
            const item=document.createElement('button'); item.type='button'; item.className='configured-item';
            item.textContent=displayNameForZone(targetId)+' → '+(fields()[path] || path);
            item.addEventListener('click',()=>{const el=elementForZone(targetId); if(el){selectWholeZone(targetId,el);el.scrollIntoView({behavior:'smooth',block:'center'});}});
            list.appendChild(item);
        });
    }

    document.getElementById('bind-btn').addEventListener('click',async()=>{
        if(!selectionState)return; const path=fieldSelect.value; if(!path)return toast('Selecciona qué información irá en este espacio.',true); if(selectionState.requiresPrecise)return toast('Selecciona únicamente el valor que cambiará.',true);
        try{await save(payloadFor('bind',{field_path:path}))}catch(error){toast(error.message,true)}
    });

    document.getElementById('ignore-btn').addEventListener('click',async()=>{
        if(!selectionState)return;
        if(selectionState.kind==='fragment'){clearSelection();toast('La selección se dejó como contenido fijo.');return;}
        try{await save(payloadFor('ignore'))}catch(error){toast(error.message,true)}
    });

    document.getElementById('unbind-btn').addEventListener('click',async()=>{if(!selectionState)return;try{await save(payloadFor('unbind'))}catch(error){toast(error.message,true)}});

    document.getElementById('custom-toggle').addEventListener('click',()=>document.getElementById('custom-panel').classList.toggle('hidden'));
    document.getElementById('custom-btn').addEventListener('click',async()=>{
        if(!selectionState)return; if(selectionState.requiresPrecise)return toast('Selecciona primero únicamente el valor que cambiará.',true);
        const label=document.getElementById('custom-label').value.trim(); if(!label)return toast('Escribe un nombre para el campo.',true);
        try{await save(payloadFor('custom',{custom_label:label,custom_type:document.getElementById('custom-type').value,custom_instruction:document.getElementById('custom-instruction').value}));document.getElementById('custom-label').value='';document.getElementById('custom-instruction').value='';document.getElementById('custom-panel').classList.add('hidden')}catch(error){toast(error.message,true)}
    });

    fieldSelect.addEventListener('change',updateConceptPreview);
    document.getElementById('use-suggested-value').addEventListener('click',()=>{if(suggestedValueState)openSelection(suggestedValueState)});
    viewer.addEventListener('mouseup',()=>setTimeout(handleTextSelection,0));

    function setViewMode(mode){viewMode=mode;document.body.classList.toggle('fields-mode',mode==='fields');document.body.classList.toggle('original-mode',mode==='original');document.getElementById('fields-mode').classList.toggle('active',mode==='fields');document.getElementById('original-mode').classList.toggle('active',mode==='original');if(mode==='original')clearSelection();annotate()}
    document.getElementById('fields-mode').addEventListener('click',()=>setViewMode('fields'));
    document.getElementById('original-mode').addEventListener('click',()=>setViewMode('original'));

    previewBtn.addEventListener('click',async()=>{
        if(!Object.keys(mapping.anchors||{}).length&&!Object.keys(mapping.fragments||{}).length)return toast('Configura al menos un campo antes de generar el ejemplo.',true);
        const original=previewBtn.textContent;previewBtn.disabled=true;previewBtn.textContent='Generando ejemplo…';
        try{const response=await fetch(previewUrl,{method:'POST',headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});const data=await response.json().catch(()=>({}));if(!response.ok)throw new Error(data.message||'No se pudo generar el ejemplo.');if(data.pdf_url)window.open(data.pdf_url,'_blank','noopener');toast('Ejemplo actualizado con tu configuración')}catch(error){toast(error.message,true)}finally{previewBtn.disabled=false;previewBtn.textContent=original}
    });

    fetch(sourceUrl,{headers:{'Accept':'application/vnd.openxmlformats-officedocument.wordprocessingml.document'}})
        .then(response=>{if(!response.ok)throw new Error('No se pudo abrir el documento.');return response.arrayBuffer()})
        .then(buffer=>{viewer.innerHTML='';viewer.classList.remove('loading');if(!window.docx||typeof window.docx.renderAsync!=='function')throw new Error('No se pudo iniciar el visor del documento.');return window.docx.renderAsync(buffer,viewer,null,{inWrapper:true,ignoreWidth:false,ignoreHeight:false,ignoreFonts:false,breakPages:true,ignoreLastRenderedPageBreak:false})})
        .then(()=>setTimeout(annotate,50))
        .catch(error=>{viewer.innerHTML='<div style="padding:30px;color:#991b1b">'+error.message+'</div>';viewer.classList.remove('loading')});
})();
</script>
</body>
</html>
