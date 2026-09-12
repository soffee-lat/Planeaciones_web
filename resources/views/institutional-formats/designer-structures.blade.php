@include('institutional-formats.designer')

<style>
    .structure-mode #viewer tr.structure-zone,
    .structure-mode #viewer table.structure-zone { position: relative; cursor: pointer; }
    .structure-mode #viewer tr.structure-zone:hover { outline: 3px solid #7c3aed; outline-offset: -3px; }
    .structure-mode #viewer table.structure-zone:hover { outline: 3px dashed #7c3aed; outline-offset: -3px; }
    .structure-mode #viewer .structure-selected { outline: 4px solid #7c3aed !important; outline-offset: -4px; background: rgba(124,58,237,.08) !important; }
    .structure-mode #viewer .template-zone { box-shadow: none !important; background: transparent !important; }
    .structure-chip { background:#ede9fe;border:1px solid #c4b5fd;color:#5b21b6;border-radius:999px;padding:4px 8px;font-size:11px;font-weight:700; }
    .structure-field { border:1px solid #e2e8f0;border-radius:9px;padding:10px;margin-top:9px;background:#fafafa; }
    .structure-field .excerpt { margin:0 0 8px; }
    .structure-actions { display:flex;gap:8px;margin-top:12px; }
    .structure-actions > * { flex:1; }
</style>

<script>
(() => {
    const structureBindingUrl = @json($structureBindingUrl);
    const previewUrl = @json($previewUrl);
    const standardFields = @json($fields);
    const structuralZones = @json($structuralZones);
    let mapping = @json($mapping);
    mapping.structures = mapping.structures || {};
    mapping.custom_fields = mapping.custom_fields || {};

    const csrf = document.querySelector('meta[name="csrf-token"]')?.content || '';
    const viewer = document.getElementById('viewer');
    const side = document.querySelector('.side');
    const toggle = document.querySelector('.view-toggle');
    const previewBtn = document.getElementById('preview-btn');
    if (!viewer || !side || !toggle) return;

    const button = document.createElement('button');
    button.type = 'button';
    button.id = 'structures-mode';
    button.className = 'btn btn-toggle btn-ghost';
    button.textContent = 'Estructuras';
    toggle.appendChild(button);

    const panel = document.createElement('div');
    panel.id = 'structure-editor';
    panel.className = 'card hidden';
    panel.innerHTML = `
        <div style="display:flex;align-items:center;justify-content:space-between;gap:8px">
            <h2 style="margin:0">Estructura repetible</h2><span class="structure-chip">Contrato v3</span>
        </div>
        <div class="muted" style="margin-top:8px">Haz clic en una fila para indicar que puede repetirse. Usa <b>Shift + clic</b> para seleccionar la tabla completa como bloque. Planeaciones clonará el OOXML original; no reconstruirá el diseño.</div>
        <div id="structure-empty" class="selection-warning" style="margin-top:10px">Selecciona una fila o tabla del documento.</div>
        <div id="structure-form" class="hidden">
            <label>Nombre de esta estructura</label>
            <input id="structure-label" placeholder="Ej. Jornada, grado, actividad, evidencia">
            <label>Instrucción para la IA</label>
            <textarea id="structure-instruction" placeholder="Ej. Genera los elementos que necesite este bloque según la planeación."></textarea>
            <div class="muted" style="margin-top:10px">Define qué ocurre en cada celda. Los campos de IA se generan por cada elemento; los datos conocidos se repiten desde sistema/currículo.</div>
            <div id="structure-fields"></div>
            <div class="structure-actions">
                <button id="structure-save" class="btn btn-primary" type="button">Guardar estructura</button>
                <button id="structure-delete" class="btn btn-danger hidden" type="button">Quitar estructura</button>
            </div>
        </div>
    `;
    const counter = side.querySelector('.counter');
    if (counter?.nextSibling) side.insertBefore(panel, counter.nextSibling);
    else side.prepend(panel);

    const zoneMap = new Map(structuralZones.map(zone => [String(zone.id), zone]));
    let active = false;
    let selected = null;

    function toast(message, error = false) {
        const el = document.createElement('div');
        el.className = 'toast' + (error ? ' error' : '');
        el.textContent = message;
        document.body.appendChild(el);
        setTimeout(() => el.remove(), 3000);
    }

    function structureForZone(zoneId) {
        return Object.entries(mapping.structures || {}).find(([, structure]) => structure && String(structure.zone_id || '') === zoneId) || null;
    }

    function structureFieldDefinition(structure) {
        const path = String(structure?.field_path || '');
        if (!path.startsWith('custom.')) return {};
        return mapping.custom_fields[path.slice('custom.'.length)] || {};
    }

    function annotateStructures() {
        viewer.querySelectorAll('tr').forEach((element, index) => {
            const id = 'r:' + index;
            if (!zoneMap.has(id)) return;
            element.classList.add('structure-zone');
            element.dataset.structureZoneId = id;
        });
        viewer.querySelectorAll('table').forEach((element, index) => {
            const id = 't:' + index;
            if (!zoneMap.has(id)) return;
            element.classList.add('structure-zone');
            element.dataset.structureZoneId = id;
        });
    }

    function setActive(value) {
        active = value;
        document.body.classList.toggle('structure-mode', active);
        button.classList.toggle('active', active);
        panel.classList.toggle('hidden', !active);
        if (active) {
            document.getElementById('fields-mode')?.classList.remove('active');
            document.getElementById('original-mode')?.classList.remove('active');
            document.getElementById('editor')?.classList.add('hidden');
            document.getElementById('label-warning')?.classList.add('hidden');
            document.getElementById('empty-state')?.classList.add('hidden');
            annotateStructures();
        } else {
            clearSelected();
        }
    }

    function clearSelected() {
        viewer.querySelectorAll('.structure-selected').forEach(el => el.classList.remove('structure-selected'));
        selected = null;
        document.getElementById('structure-empty')?.classList.remove('hidden');
        document.getElementById('structure-form')?.classList.add('hidden');
    }

    function selectStructure(element, zoneId) {
        viewer.querySelectorAll('.structure-selected').forEach(el => el.classList.remove('structure-selected'));
        element.classList.add('structure-selected');
        const zone = zoneMap.get(zoneId);
        if (!zone) return;
        selected = {element, zoneId, zone};
        document.getElementById('structure-empty').classList.add('hidden');
        document.getElementById('structure-form').classList.remove('hidden');
        renderStructureForm();
    }

    function childElements() {
        if (!selected) return [];
        if (String(selected.zone.kind) === 'row') {
            return Array.from(selected.element.querySelectorAll(':scope > td, :scope > th'));
        }
        return Array.from(selected.element.querySelectorAll('td, th'));
    }

    function childZoneIds() {
        return Array.isArray(selected?.zone?.child_zone_ids) ? selected.zone.child_zone_ids.map(String) : [];
    }

    function pathOptions(selectedPath = '') {
        let html = '<option value="">Selecciona un dato conocido…</option>';
        Object.entries(standardFields).forEach(([path, label]) => {
            const safePath = String(path).replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;');
            const safeLabel = String(label).replace(/&/g,'&amp;').replace(/</g,'&lt;');
            html += `<option value="${safePath}" ${path === selectedPath ? 'selected' : ''}>${safeLabel}</option>`;
        });
        return html;
    }

    function renderStructureForm() {
        if (!selected) return;
        const existingEntry = structureForZone(selected.zoneId);
        const existing = existingEntry?.[1] || null;
        const definition = structureFieldDefinition(existing);
        document.getElementById('structure-label').value = definition.label || '';
        document.getElementById('structure-instruction').value = definition.instruction || '';
        document.getElementById('structure-delete').classList.toggle('hidden', !existing);

        const ids = childZoneIds();
        const elements = childElements();
        const fields = document.getElementById('structure-fields');
        fields.innerHTML = '';

        ids.forEach((zoneId, index) => {
            const binding = existing?.bindings?.[zoneId] || null;
            const itemDef = binding?.source === 'item' ? (definition.item_fields?.[binding.item_key] || {}) : {};
            const card = document.createElement('div');
            card.className = 'structure-field';
            card.dataset.zoneId = zoneId;
            const excerpt = String(elements[index]?.textContent || zoneMap.get(zoneId)?.text_excerpt || '').trim();
            const mode = binding?.source === 'item' ? 'ai' : (binding?.source === 'path' ? 'path' : 'manual');
            card.innerHTML = `
                <div class="excerpt">${excerpt ? excerpt.replace(/&/g,'&amp;').replace(/</g,'&lt;') : 'Celda vacía'}</div>
                <label>Uso de esta celda</label>
                <select class="structure-field-mode">
                    <option value="manual" ${mode === 'manual' ? 'selected' : ''}>Conservar / manual</option>
                    <option value="path" ${mode === 'path' ? 'selected' : ''}>Dato existente de sistema o currículo</option>
                    <option value="ai" ${mode === 'ai' ? 'selected' : ''}>Contenido variable generado por IA</option>
                </select>
                <div class="structure-path ${mode === 'path' ? '' : 'hidden'}">
                    <label>Dato</label><select class="structure-field-path">${pathOptions(binding?.field_path || '')}</select>
                </div>
                <div class="structure-ai ${mode === 'ai' ? '' : 'hidden'}">
                    <label>Nombre</label><input class="structure-field-label" value="${String(itemDef.label || '').replace(/&/g,'&amp;').replace(/"/g,'&quot;').replace(/</g,'&lt;')}" placeholder="Ej. Actividad, PDA, evidencia">
                    <label>Tipo</label><select class="structure-field-type">
                        <option value="text" ${itemDef.type === 'text' ? 'selected' : ''}>Texto corto</option>
                        <option value="long_text" ${!itemDef.type || itemDef.type === 'long_text' ? 'selected' : ''}>Texto largo</option>
                        <option value="date" ${itemDef.type === 'date' ? 'selected' : ''}>Fecha</option>
                        <option value="list" ${itemDef.type === 'list' ? 'selected' : ''}>Lista</option>
                    </select>
                    <label>Indicaciones</label><textarea class="structure-field-instruction" placeholder="Qué debe contener esta celda en cada repetición">${String(itemDef.instruction || '').replace(/&/g,'&amp;').replace(/</g,'&lt;')}</textarea>
                </div>
            `;
            const modeSelect = card.querySelector('.structure-field-mode');
            modeSelect.addEventListener('change', () => {
                card.querySelector('.structure-path').classList.toggle('hidden', modeSelect.value !== 'path');
                card.querySelector('.structure-ai').classList.toggle('hidden', modeSelect.value !== 'ai');
            });
            fields.appendChild(card);
        });
    }

    function payload() {
        if (!selected) return null;
        const fields = Array.from(document.querySelectorAll('#structure-fields .structure-field')).map(card => {
            const mode = card.querySelector('.structure-field-mode').value;
            return {
                zone_id: card.dataset.zoneId,
                mode,
                field_path: mode === 'path' ? card.querySelector('.structure-field-path').value : null,
                label: mode === 'ai' ? card.querySelector('.structure-field-label').value.trim() : null,
                type: mode === 'ai' ? card.querySelector('.structure-field-type').value : null,
                instruction: mode === 'ai' ? card.querySelector('.structure-field-instruction').value : null,
                required: true,
            };
        });
        return {
            zone_id: selected.zoneId,
            mode: 'save',
            kind: selected.zone.kind === 'table' ? 'repeat_block' : 'repeat_row',
            label: document.getElementById('structure-label').value.trim(),
            instruction: document.getElementById('structure-instruction').value,
            fields,
        };
    }

    async function send(body) {
        const response = await fetch(structureBindingUrl, {
            method: 'POST',
            headers: {'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) throw new Error(data.message || 'No se pudo guardar la estructura.');
        mapping = data.mapping || mapping;
        return data;
    }

    button.addEventListener('click', () => setActive(!active));
    document.getElementById('fields-mode')?.addEventListener('click', () => { if (active) setActive(false); });
    document.getElementById('original-mode')?.addEventListener('click', () => { if (active) setActive(false); });

    viewer.addEventListener('click', event => {
        if (!active) return;
        const table = event.target.closest('table');
        const row = event.target.closest('tr');
        const target = event.shiftKey && table ? table : row;
        if (!target) return;
        const all = target.tagName.toLowerCase() === 'table' ? Array.from(viewer.querySelectorAll('table')) : Array.from(viewer.querySelectorAll('tr'));
        const index = all.indexOf(target);
        if (index < 0) return;
        const zoneId = (target.tagName.toLowerCase() === 'table' ? 't:' : 'r:') + index;
        if (!zoneMap.has(zoneId)) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        selectStructure(target, zoneId);
    }, true);

    document.getElementById('structure-save').addEventListener('click', async () => {
        const body = payload();
        if (!body) return;
        if (!body.label) return toast('Escribe un nombre para la estructura.', true);
        if (!body.fields.some(field => field.mode === 'ai')) return toast('Define al menos una celda variable generada por IA.', true);
        if (body.fields.some(field => field.mode === 'path' && !field.field_path)) return toast('Selecciona el dato conocido para cada celda configurada.', true);
        if (body.fields.some(field => field.mode === 'ai' && !field.label)) return toast('Pon nombre a cada celda generada por IA.', true);
        try {
            await send(body);
            toast('Estructura guardada. Recargando el diseñador…');
            setTimeout(() => window.location.reload(), 450);
        } catch (error) { toast(error.message, true); }
    });

    document.getElementById('structure-delete').addEventListener('click', async () => {
        if (!selected) return;
        try {
            await send({zone_id:selected.zoneId, mode:'delete'});
            toast('Estructura eliminada. Recargando…');
            setTimeout(() => window.location.reload(), 450);
        } catch (error) { toast(error.message, true); }
    });

    // El botón original sólo considera anchors/fragments. Si existe contrato
    // estructural, interceptamos la vista previa para incluirlo.
    previewBtn?.addEventListener('click', async event => {
        if (!Object.keys(mapping.structures || {}).length) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        const original = previewBtn.textContent;
        previewBtn.disabled = true;
        previewBtn.textContent = 'Generando ejemplo…';
        try {
            const response = await fetch(previewUrl, {method:'POST', headers:{'Accept':'application/json','X-CSRF-TOKEN':csrf}});
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'No se pudo generar el ejemplo.');
            if (data.pdf_url) window.open(data.pdf_url, '_blank', 'noopener');
            toast('Ejemplo actualizado con estructuras repetibles');
        } catch (error) { toast(error.message, true); }
        finally { previewBtn.disabled = false; previewBtn.textContent = original; }
    }, true);

    const observer = new MutationObserver(() => annotateStructures());
    observer.observe(viewer, {childList:true, subtree:true});
    setTimeout(annotateStructures, 250);
})();
</script>
