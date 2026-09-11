<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Diseñador visual · {{ $format->name }}</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;background:#f1f5f9}
        *{box-sizing:border-box} body{margin:0}.topbar{height:64px;background:#fff;border-bottom:1px solid #e2e8f0;display:flex;align-items:center;justify-content:space-between;padding:0 20px;position:sticky;top:0;z-index:50}.topbar h1{font-size:16px;margin:0}.topbar small{color:#64748b}.btn{border:0;border-radius:9px;padding:9px 13px;font-weight:650;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px}.btn-primary{background:#0f766e;color:#fff}.btn-secondary{background:#e2e8f0;color:#0f172a}.btn-danger{background:#fee2e2;color:#991b1b}.btn-blue{background:#dbeafe;color:#1d4ed8}.layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;min-height:calc(100vh - 64px)}.canvas{padding:24px;overflow:auto}.side{background:#fff;border-left:1px solid #e2e8f0;padding:20px;overflow:auto;position:sticky;top:64px;height:calc(100vh - 64px)}.notice{background:#ecfeff;border:1px solid #a5f3fc;border-radius:12px;padding:12px 14px;margin-bottom:16px;color:#155e75;font-size:13px}.legend{display:flex;gap:8px;flex-wrap:wrap;margin:0 0 16px}.legend span{font-size:12px;padding:5px 8px;border-radius:999px;border:1px solid}.l-green{background:#dcfce7;border-color:#86efac!important}.l-yellow{background:#fef9c3;border-color:#fde047!important}.l-blue{background:#dbeafe;border-color:#93c5fd!important}.l-gray{background:#f1f5f9;border-color:#cbd5e1!important}.l-red{background:#fee2e2;border-color:#fca5a5!important}.viewer-shell{background:#cbd5e1;border-radius:14px;padding:24px;min-height:70vh;overflow:auto}.viewer-shell.loading{display:grid;place-items:center;color:#475569}.docx-wrapper{margin:0 auto}.template-zone{cursor:pointer;transition:box-shadow .15s,background .15s;position:relative}.template-zone:hover{box-shadow:inset 0 0 0 2px #0f766e!important}.zone-bound{box-shadow:inset 0 0 0 2px #22c55e;background:rgba(34,197,94,.08)!important}.zone-custom{box-shadow:inset 0 0 0 2px #3b82f6;background:rgba(59,130,246,.08)!important}.zone-ignored{box-shadow:inset 0 0 0 1px #94a3b8;background:rgba(148,163,184,.08)!important}.zone-unmapped{box-shadow:inset 0 0 0 2px #eab308;background:rgba(234,179,8,.08)!important}.zone-selected{box-shadow:inset 0 0 0 3px #0f766e!important}.card{border:1px solid #e2e8f0;border-radius:12px;padding:14px;margin-bottom:14px}.card h2{font-size:15px;margin:0 0 8px}.muted{color:#64748b;font-size:13px;line-height:1.45}.zone-title{font-weight:750;font-size:14px}.excerpt{background:#f8fafc;border-radius:8px;padding:9px;margin:9px 0;font-size:12px;max-height:94px;overflow:auto}.field-row{display:flex;gap:8px;margin-top:10px}.field-row>*{flex:1}select,input,textarea{width:100%;border:1px solid #cbd5e1;border-radius:8px;padding:9px;background:#fff;color:#0f172a}textarea{min-height:78px;resize:vertical}label{font-size:12px;font-weight:700;display:block;margin:10px 0 5px}.hidden{display:none!important}.status{font-size:12px;border-radius:999px;padding:4px 8px;background:#f1f5f9}.configured-list{display:grid;gap:7px}.configured-item{font-size:12px;padding:8px;border:1px solid #e2e8f0;border-radius:8px}.toast{position:fixed;right:22px;bottom:22px;background:#0f172a;color:#fff;padding:11px 15px;border-radius:10px;z-index:99;box-shadow:0 10px 30px #0003}.error{background:#991b1b}.spinner{width:26px;height:26px;border:3px solid #cbd5e1;border-top-color:#0f766e;border-radius:50%;animation:spin .8s linear infinite}@keyframes spin{to{transform:rotate(360deg)}}
        @media(max-width:1000px){.layout{grid-template-columns:1fr}.side{position:relative;top:auto;height:auto;border-left:0;border-top:1px solid #e2e8f0}.canvas{padding:12px}.viewer-shell{padding:10px}}
    </style>
</head>
<body>
<header class="topbar">
    <div><h1>Diseñador visual de formato</h1><small>{{ $format->name }} · v{{ $version->number }}</small></div>
    <div style="display:flex;gap:8px"><a class="btn btn-secondary" href="{{ $backUrl }}">Volver</a></div>
</header>
<div class="layout">
    <main class="canvas">
        <div class="notice"><strong>Selecciona directamente una zona del documento.</strong> Puedes asignarle un campo existente, crear uno nuevo o indicar que esa parte se conserve sin modificar. El archivo original no se altera.</div>
        <div class="legend"><span class="l-green">Verde · campo configurado</span><span class="l-yellow">Amarillo · sugerencia por revisar</span><span class="l-blue">Azul · campo personalizado</span><span class="l-gray">Gris · no modificar</span></div>
        <div id="viewer" class="viewer-shell loading"><div><div class="spinner" style="margin:auto"></div><p>Preparando tu documento…</p></div></div>
    </main>
    <aside class="side">
        <div class="card"><h2>Cómo usarlo</h2><div class="muted">Haz clic sobre una celda o párrafo del Word. A la derecha aparecerá esa zona. El sistema ya marcó sus sugerencias, pero tú tienes la última palabra.</div></div>
        <div id="empty-state" class="card"><h2>Selecciona una zona</h2><div class="muted">Puedes elegir incluso partes que el análisis automático no reconoció.</div></div>
        <div id="editor" class="hidden">
            <div class="card">
                <div style="display:flex;justify-content:space-between;gap:10px"><div class="zone-title" id="zone-name">Zona</div><span class="status" id="zone-status">Sin configurar</span></div>
                <div class="excerpt" id="zone-excerpt">—</div>
                <label for="field-select">¿Qué información debe ir aquí?</label>
                <select id="field-select"><option value="">Selecciona un campo…</option></select>
                <div class="field-row"><button id="bind-btn" class="btn btn-primary">Guardar campo</button><button id="ignore-btn" class="btn btn-secondary">No modificar</button></div>
                <button id="unbind-btn" class="btn btn-danger" style="margin-top:8px;width:100%;justify-content:center">Quitar relación</button>
            </div>
            <div class="card">
                <h2>Crear un campo nuevo</h2>
                <div class="muted">Úsalo cuando tu escuela pide algo que no existe en los campos propuestos.</div>
                <label>Nombre del campo</label><input id="custom-label" placeholder="Ej. Producto integrador">
                <label>Tipo de información</label><select id="custom-type"><option value="text">Texto corto</option><option value="long_text" selected>Texto largo</option><option value="date">Fecha</option><option value="list">Lista</option><option value="table">Tabla</option><option value="repeating_block">Bloque repetible</option></select>
                <label>Indicaciones para generarlo</label><textarea id="custom-instruction" placeholder="Ej. Describe el producto final que elaborarán los estudiantes."></textarea>
                <button id="custom-btn" class="btn btn-blue" style="margin-top:9px;width:100%;justify-content:center">Crear y usar este campo</button>
            </div>
        </div>
        <div class="card"><h2>Campos configurados</h2><div id="configured-list" class="configured-list"></div></div>
    </aside>
</div>
<script src="https://cdn.jsdelivr.net/npm/jszip@3.10.1/dist/jszip.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/docx-preview@0.4.0/dist/docx-preview.min.js"></script>
<script>
(() => {
    const sourceUrl = @json($sourceUrl);
    const bindingUrl = @json($bindingUrl);
    const initialZones = @json($zones);
    const anchors = @json($anchors);
    const standardFields = @json($fields);
    let mapping = @json($mapping);
    mapping.anchors = mapping.anchors || {};
    mapping.custom_fields = mapping.custom_fields || {};
    mapping.ignored_zones = mapping.ignored_zones || [];
    const zoneMap = new Map(initialZones.map(z => [z.id, z]));
    let selectedId = null;
    let selectedElement = null;

    const viewer = document.getElementById('viewer');
    const editor = document.getElementById('editor');
    const empty = document.getElementById('empty-state');
    const fieldSelect = document.getElementById('field-select');
    const csrf = document.querySelector('meta[name="csrf-token"]').content;

    function fields() {
        const result = {...standardFields};
        Object.entries(mapping.custom_fields || {}).forEach(([key, def]) => result['custom.' + key] = 'Personalizado · ' + (def.label || key));
        return result;
    }
    function refreshFieldOptions() {
        const current = fieldSelect.value;
        fieldSelect.innerHTML = '<option value="">Selecciona un campo…</option>';
        Object.entries(fields()).forEach(([path,label]) => {
            const o=document.createElement('option'); o.value=path; o.textContent=label; fieldSelect.appendChild(o);
        });
        if (current && fields()[current]) fieldSelect.value=current;
    }
    function anchorForTarget(id) {
        return anchors.find(a => a && a.target_id === id && mapping.anchors[a.id]) || anchors.find(a => a && a.id === id);
    }
    function bindingKey(id) {
        const a = anchorForTarget(id);
        return a && mapping.anchors[a.id] ? a.id : id;
    }
    function pathFor(id) {
        const key=bindingKey(id); return mapping.anchors[key] || null;
    }
    function isIgnored(id) { return (mapping.ignored_zones || []).includes(id); }
    function isSuggested(id) {
        const a=anchors.find(x => x && (x.id===id || x.target_id===id)); return !!(a && a.suggested_path && !pathFor(id));
    }
    function decorate(el,id) {
        el.classList.add('template-zone');
        el.classList.remove('zone-bound','zone-custom','zone-ignored','zone-unmapped','zone-selected');
        const path=pathFor(id);
        if (isIgnored(id)) el.classList.add('zone-ignored');
        else if (path && path.startsWith('custom.')) el.classList.add('zone-custom');
        else if (path) el.classList.add('zone-bound');
        else if (isSuggested(id)) el.classList.add('zone-unmapped');
        if (selectedId===id) el.classList.add('zone-selected');
        const label=path ? (fields()[path] || path) : (isIgnored(id) ? 'No modificar' : (isSuggested(id) ? 'Sugerencia por revisar' : 'Seleccionar zona'));
        el.title=label;
    }
    function annotate() {
        viewer.querySelectorAll('td').forEach((el,i) => { const id='c:'+i; if(zoneMap.has(id)){el.dataset.zoneId=id; decorate(el,id); el.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();selectZone(id,el);});} });
        viewer.querySelectorAll('p').forEach((el,i) => { const id='p:'+i; if(zoneMap.has(id) && !el.closest('td')){el.dataset.zoneId=id; decorate(el,id); el.addEventListener('click',e=>{e.preventDefault();e.stopPropagation();selectZone(id,el);});} });
        refreshConfigured();
    }
    function selectZone(id,el) {
        if(selectedElement) decorate(selectedElement,selectedId);
        selectedId=id; selectedElement=el; decorate(el,id);
        empty.classList.add('hidden'); editor.classList.remove('hidden');
        const zone=zoneMap.get(id)||{}; const a=anchorForTarget(id); const path=pathFor(id); const key=bindingKey(id);
        document.getElementById('zone-name').textContent=(a && a.label ? '“'+a.label+'” · ' : '') + id;
        document.getElementById('zone-excerpt').textContent=(a && a.current_value_excerpt) || zone.text_excerpt || (zone.is_blank ? 'Zona vacía' : 'Sin texto visible');
        document.getElementById('zone-status').textContent=isIgnored(id)?'No modificar':(path?(path.startsWith('custom.')?'Personalizado':'Configurado'):(a&&a.suggested_path?'Sugerencia':'Sin configurar'));
        refreshFieldOptions(); fieldSelect.value=path || (a&&a.suggested_path) || '';
        document.getElementById('unbind-btn').style.display=(path || isIgnored(id))?'inline-flex':'none';
        document.getElementById('bind-btn').dataset.bindingKey=key;
    }
    async function save(payload) {
        const response=await fetch(bindingUrl,{method:'POST',headers:{'Content-Type':'application/json','Accept':'application/json','X-CSRF-TOKEN':csrf},body:JSON.stringify(payload)});
        const data=await response.json().catch(()=>({}));
        if(!response.ok) throw new Error(data.message || 'No se pudo guardar la configuración.');
        mapping=data.mapping || mapping; refreshFieldOptions(); annotate(); if(selectedId&&selectedElement) selectZone(selectedId,selectedElement); toast('Cambio guardado');
    }
    function toast(message,error=false){const el=document.createElement('div');el.className='toast'+(error?' error':'');el.textContent=message;document.body.appendChild(el);setTimeout(()=>el.remove(),2600)}
    function refreshConfigured(){
        const list=document.getElementById('configured-list'); list.innerHTML='';
        const entries=Object.entries(mapping.anchors||{});
        if(!entries.length){list.innerHTML='<div class="muted">Todavía no hay campos configurados.</div>';return;}
        entries.forEach(([zone,path])=>{const d=document.createElement('div');d.className='configured-item';d.textContent=zone+' → '+(fields()[path]||path);list.appendChild(d);});
    }
    document.getElementById('bind-btn').addEventListener('click',async()=>{if(!selectedId)return;const path=fieldSelect.value;if(!path){toast('Selecciona un campo.',true);return;}try{await save({zone_id:document.getElementById('bind-btn').dataset.bindingKey||selectedId,mode:'bind',field_path:path})}catch(e){toast(e.message,true)}});
    document.getElementById('ignore-btn').addEventListener('click',async()=>{if(!selectedId)return;try{await save({zone_id:bindingKey(selectedId),mode:'ignore'})}catch(e){toast(e.message,true)}});
    document.getElementById('unbind-btn').addEventListener('click',async()=>{if(!selectedId)return;try{await save({zone_id:bindingKey(selectedId),mode:'unbind'})}catch(e){toast(e.message,true)}});
    document.getElementById('custom-btn').addEventListener('click',async()=>{if(!selectedId)return;const label=document.getElementById('custom-label').value.trim();if(!label){toast('Escribe un nombre para el campo.',true);return;}try{await save({zone_id:bindingKey(selectedId),mode:'custom',custom_label:label,custom_type:document.getElementById('custom-type').value,custom_instruction:document.getElementById('custom-instruction').value});document.getElementById('custom-label').value='';document.getElementById('custom-instruction').value=''}catch(e){toast(e.message,true)}});

    fetch(sourceUrl,{headers:{'Accept':'application/vnd.openxmlformats-officedocument.wordprocessingml.document'}})
        .then(r=>{if(!r.ok)throw new Error('No se pudo abrir el documento.');return r.arrayBuffer()})
        .then(buffer=>{viewer.innerHTML='';viewer.classList.remove('loading');return docx.renderAsync(buffer,viewer,null,{inWrapper:true,ignoreWidth:false,ignoreHeight:false,ignoreFonts:false,breakPages:true,ignoreLastRenderedPageBreak:false})})
        .then(()=>setTimeout(annotate,50))
        .catch(e=>{viewer.innerHTML='<div style="padding:30px;color:#991b1b">'+e.message+'</div>';viewer.classList.remove('loading')});
})();
</script>
</body>
</html>
