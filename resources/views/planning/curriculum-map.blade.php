<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Mapa curricular · Planeaciones</title>
    <style>
        :root{font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;color:#0f172a;background:#f8fafc}
        *{box-sizing:border-box}body{margin:0}.top{background:#fff;border-bottom:1px solid #e2e8f0;padding:14px 22px;display:flex;align-items:center;justify-content:space-between;gap:16px;position:sticky;top:0;z-index:20}.top h1{margin:0;font-size:18px}.top small{color:#64748b}.wrap{max-width:1180px;margin:0 auto;padding:24px}.hero{background:linear-gradient(135deg,#f0fdfa,#fff);border:1px solid #99f6e4;border-radius:18px;padding:22px;margin-bottom:18px}.hero h2{margin:0 0 8px;font-size:24px}.hero p{margin:5px 0;color:#475569;line-height:1.5}.stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.pill{padding:6px 9px;border-radius:999px;background:#fff;border:1px solid #cbd5e1;font-size:12px}.grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px}.section{background:#fff;border:1px solid #e2e8f0;border-radius:16px;padding:18px;margin-bottom:16px}.section h3{margin:0 0 6px;font-size:17px}.muted{color:#64748b;font-size:13px;line-height:1.45}.item{border:1px solid #e2e8f0;border-radius:13px;padding:14px;margin-top:11px}.item.accepted{border-color:#86efac;background:#f0fdf4}.item.rejected{border-color:#fecaca;background:#fff7f7;opacity:.78}.item.pending{border-color:#fde68a;background:#fffbeb}.item-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.code{font-size:11px;font-weight:800;color:#0f766e;text-transform:uppercase}.title{font-weight:750;margin-top:2px}.field{display:inline-block;margin-top:6px;padding:4px 7px;border-radius:7px;background:#ecfeff;color:#155e75;font-size:11px}.reason{margin-top:7px;font-size:12px;color:#475569}.status{white-space:nowrap;padding:4px 7px;border-radius:999px;font-size:11px;font-weight:700}.s-accepted{background:#dcfce7;color:#166534}.s-rejected{background:#fee2e2;color:#991b1b}.s-pending{background:#fef3c7;color:#92400e}.actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:11px}.btn{border:0;border-radius:9px;padding:8px 11px;font-weight:700;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.primary{background:#0f766e;color:#fff}.success{background:#dcfce7;color:#166534}.danger{background:#fee2e2;color:#991b1b}.secondary{background:#e2e8f0;color:#334155}.outline{background:#fff;border:1px solid #cbd5e1;color:#334155}.pda{margin:9px 0 0 18px;padding:10px 12px;border-left:3px solid #cbd5e1;background:#f8fafc;border-radius:0 9px 9px 0}.pda.accepted{border-left-color:#22c55e}.pda.rejected{border-left-color:#ef4444;opacity:.7}.pda.pending{border-left-color:#f59e0b}.pda-text{font-size:13px;line-height:1.45}.aside{position:sticky;top:78px;align-self:start}.summary-row{display:flex;justify-content:space-between;gap:10px;padding:7px 0;border-bottom:1px solid #f1f5f9;font-size:13px}.summary-row:last-child{border:0}.notice{padding:11px 13px;border-radius:10px;margin-bottom:14px;font-size:13px;line-height:1.45}.notice.error{background:#fef2f2;border:1px solid #fecaca;color:#991b1b}.notice.ok{background:#f0fdf4;border:1px solid #bbf7d0;color:#166534}.notice.info{background:#eff6ff;border:1px solid #bfdbfe;color:#1e40af}.add-grid{display:grid;gap:10px;margin-top:12px}select{width:100%;border:1px solid #cbd5e1;border-radius:9px;padding:9px;background:#fff}.confirm{width:100%;padding:12px;margin-top:10px}.pending-warning{font-size:12px;color:#92400e;background:#fffbeb;border:1px solid #fde68a;padding:9px;border-radius:9px;margin-top:10px}.empty{padding:18px;border:1px dashed #cbd5e1;border-radius:12px;color:#64748b;text-align:center;margin-top:10px}.top-actions{display:flex;gap:8px;flex-wrap:wrap}.axes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.axes .item{margin-top:0}.plan-structure{margin-top:16px;padding:14px;border:1px solid #cbd5e1;border-radius:12px;background:#fff}.plan-week{padding:10px 0;border-top:1px solid #e2e8f0}.plan-week:first-child{border-top:0;padding-top:0}.plan-week:last-child{padding-bottom:0}.plan-week-title{font-size:12px;font-weight:800;color:#334155}.plan-topic{margin-top:5px;font-size:12px;color:#475569}.plan-topic strong{color:#0f172a}@media(max-width:900px){.grid{grid-template-columns:1fr}.aside{position:static}.wrap{padding:14px}.top{align-items:flex-start}.axes{grid-template-columns:1fr}.item-head{flex-direction:column}.status{align-self:flex-start}}
    </style>
</head>
<body>
<header class="top">
    <div><h1>Mapa curricular</h1><small>{{ $request->group?->name ?? 'Grupo' }} · {{ $request->project }}</small></div>
    <div class="top-actions">
        <a class="btn outline" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">Nueva planeación</a>
        <a class="btn secondary" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">Mis planeaciones</a>
    </div>
</header>

@php
    $statusLabel = fn (string $status) => match ($status) { 'accepted' => 'Incluido', 'rejected' => 'Descartado', default => 'Por decidir' };
    $statusClass = fn (string $status) => match ($status) { 'accepted' => 's-accepted', 'rejected' => 's-rejected', default => 's-pending' };
    $contentPdas = $pdas->groupBy('curricular_content_id');
@endphp

<main class="wrap">
    <section class="hero">
        <h2>Estas son las conexiones que encontramos</h2>
        <p>Revísalas antes de generar. Aquí decides qué sí representa lo que quieres trabajar con tu grupo.</p>
        <p><strong>{{ $request->project }}</strong>@if($request->topic) · {{ $request->topic }}@endif</p>

        @if($request->planningWeeks->isNotEmpty())
            <div class="plan-structure">
                @if($request->integrative_project)
                    <div style="margin-bottom:10px">
                        <div class="code">Proyecto integrador</div>
                        <div class="title">{{ $request->integrative_project }}</div>
                        @if($request->integrative_project_purpose)
                            <div class="reason">{{ $request->integrative_project_purpose }}</div>
                        @endif
                    </div>
                @endif

                @foreach($request->planningWeeks as $week)
                    <div class="plan-week">
                        <div class="plan-week-title">Semana {{ $week->sequence }} · {{ $week->label }}</div>
                        @foreach($week->topics as $topic)
                            <div class="plan-topic">
                                <strong>{{ $topic->subject?->name ?? 'Materia' }}:</strong>
                                {{ $topic->topic }}
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        <div class="stats">
            <span class="pill">{{ count($suggestion['content_ids']) }} contenido(s) sugerido(s)</span>
            <span class="pill">{{ count($suggestion['pda_ids']) }} PDA sugerido(s)</span>
            <span class="pill">{{ count($suggestion['formative_field_ids']) }} campo(s) formativo(s)</span>
            <span class="pill">{{ count($suggestion['axis_ids']) }} eje(s)</span>
            <span class="pill">{{ $suggestion['has_strong_match'] ? 'Coincidencia clara' : 'Coincidencia exploratoria' }}</span>
        </div>
    </section>

    @if ($errors->has('curriculum_map'))
        <div class="notice error">{{ $errors->first('curriculum_map') }}</div>
    @endif
    @if (session('curriculum_map_status'))
        <div class="notice ok">{{ session('curriculum_map_status') }}</div>
    @endif

    <div class="grid">
        <div>
            <section class="section">
                <div class="item-head">
                    <div><h3>Contenidos y PDA</h3><div class="muted">El campo formativo se deriva del contenido. Puedes aceptar o descartar cada conexión.</div></div>
                    @if($pending_count > 0)
                    <form method="POST" action="{{ route('planning.curriculum-map.accept-all', $request) }}">
                        @csrf
                        <button class="btn primary" type="submit">Aceptar todas las sugerencias</button>
                    </form>
                    @endif
                </div>

                @forelse($contents as $content)
                    @php
                        $status = $statuses['content'][$content->id] ?? 'pending';
                        $origin = $origins['content'][$content->id] ?? 'suggested';
                        $relatedPdas = $contentPdas->get($content->id, collect());
                    @endphp
                    <article class="item {{ $status }}">
                        <div class="item-head">
                            <div>
                                <div class="code">{{ $content->code }}</div>
                                <div class="title">{{ $content->title }}</div>
                                @if($content->formativeField)<span class="field">Campo formativo · {{ $content->formativeField->name }}</span>@endif
                                @if(isset($suggestion['reasons'][$content->id]))<div class="reason">Por qué se propone: {{ $suggestion['reasons'][$content->id] }}</div>@endif
                                @if($origin === 'teacher_added')<div class="reason">Agregado por ti desde el catálogo.</div>@endif
                            </div>
                            <span class="status {{ $statusClass($status) }}">{{ $statusLabel($status) }}</span>
                        </div>
                        <div class="actions">
                            @if($status !== 'accepted')
                            <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf
                                <input type="hidden" name="entity_type" value="content"><input type="hidden" name="entity_id" value="{{ $content->id }}"><input type="hidden" name="decision" value="accept">
                                <button class="btn success" type="submit">Incluir contenido</button>
                            </form>
                            @endif
                            @if($status !== 'rejected')
                            <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf
                                <input type="hidden" name="entity_type" value="content"><input type="hidden" name="entity_id" value="{{ $content->id }}"><input type="hidden" name="decision" value="reject">
                                <button class="btn danger" type="submit">{{ $origin === 'teacher_added' ? 'Quitar' : 'Descartar' }}</button>
                            </form>
                            @endif
                        </div>

                        @foreach($relatedPdas as $pda)
                            @php $pdaStatus = $statuses['pda'][$pda->id] ?? 'pending'; $pdaOrigin = $origins['pda'][$pda->id] ?? 'suggested'; @endphp
                            <div class="pda {{ $pdaStatus }}">
                                <div class="item-head">
                                    <div class="pda-text"><strong>{{ $pda->code }}</strong> · {{ $pda->full_text }}</div>
                                    <span class="status {{ $statusClass($pdaStatus) }}">{{ $statusLabel($pdaStatus) }}</span>
                                </div>
                                <div class="actions">
                                    @if($pdaStatus !== 'accepted')
                                    <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf
                                        <input type="hidden" name="entity_type" value="pda"><input type="hidden" name="entity_id" value="{{ $pda->id }}"><input type="hidden" name="decision" value="accept">
                                        <button class="btn success" type="submit">Incluir PDA</button>
                                    </form>
                                    @endif
                                    @if($pdaStatus !== 'rejected')
                                    <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf
                                        <input type="hidden" name="entity_type" value="pda"><input type="hidden" name="entity_id" value="{{ $pda->id }}"><input type="hidden" name="decision" value="reject">
                                        <button class="btn danger" type="submit">{{ $pdaOrigin === 'teacher_added' ? 'Quitar' : 'Descartar' }}</button>
                                    </form>
                                    @endif
                                </div>
                            </div>
                        @endforeach
                    </article>
                @empty
                    <div class="empty">No encontramos contenidos por coincidencia directa. Puedes agregarlos desde el catálogo de esta misma página.</div>
                @endforelse
            </section>

            <section class="section">
                <h3>Ejes articuladores</h3>
                <div class="muted" style="margin-bottom:12px">Sólo sugerimos ejes cuando encontramos coincidencias en el catálogo; no se fuerzan automáticamente.</div>
                <div class="axes">
                @forelse($axes as $axis)
                    @php $status = $statuses['axis'][$axis->id] ?? 'pending'; $origin = $origins['axis'][$axis->id] ?? 'suggested'; @endphp
                    <article class="item {{ $status }}">
                        <div class="item-head"><div><div class="code">{{ $axis->code }}</div><div class="title">{{ $axis->name }}</div></div><span class="status {{ $statusClass($status) }}">{{ $statusLabel($status) }}</span></div>
                        <div class="actions">
                            @if($status !== 'accepted')
                            <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf<input type="hidden" name="entity_type" value="axis"><input type="hidden" name="entity_id" value="{{ $axis->id }}"><input type="hidden" name="decision" value="accept"><button class="btn success">Incluir</button></form>
                            @endif
                            @if($status !== 'rejected')
                            <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf<input type="hidden" name="entity_type" value="axis"><input type="hidden" name="entity_id" value="{{ $axis->id }}"><input type="hidden" name="decision" value="reject"><button class="btn danger">{{ $origin === 'teacher_added' ? 'Quitar' : 'Descartar' }}</button></form>
                            @endif
                        </div>
                    </article>
                @empty
                    <div class="empty">No se sugirió un eje automáticamente. Puedes agregar uno si aporta a tu planeación.</div>
                @endforelse
                </div>
            </section>
        </div>

        <aside class="aside">
            <section class="section">
                <h3>Tu mapa hasta ahora</h3>
                <div class="summary-row"><span>Contenidos incluidos</span><strong>{{ count($selected['contents']) }}</strong></div>
                <div class="summary-row"><span>PDA incluidos</span><strong>{{ count($selected['pdas']) }}</strong></div>
                <div class="summary-row"><span>Ejes incluidos</span><strong>{{ count($selected['axes']) }}</strong></div>
                <div class="summary-row"><span>Por decidir</span><strong>{{ $pending_count }}</strong></div>
                @if($pending_count > 0)<div class="pending-warning">Para confirmar necesitamos saber qué haces con cada sugerencia. “Aceptar todas” resuelve las pendientes de una vez y después puedes quitar las que no correspondan.</div>@endif
            </section>

            <section class="section">
                <h3>Agregar desde el catálogo</h3>
                <div class="muted">¿Falta algo? Agrega una selección compatible con el currículo y grado de tu grupo.</div>
                <div class="add-grid">
                    <form method="POST" action="{{ route('planning.curriculum-map.add', $request) }}">@csrf
                        <input type="hidden" name="entity_type" value="content">
                        <select name="entity_id" required><option value="">Agregar contenido…</option>@foreach($catalog['contents'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                        <button class="btn outline" style="width:100%;margin-top:6px">Agregar contenido</button>
                    </form>
                    <form method="POST" action="{{ route('planning.curriculum-map.add', $request) }}">@csrf
                        <input type="hidden" name="entity_type" value="pda">
                        <select name="entity_id" required><option value="">Agregar PDA…</option>@foreach($catalog['pdas'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                        <button class="btn outline" style="width:100%;margin-top:6px">Agregar PDA</button>
                    </form>
                    <form method="POST" action="{{ route('planning.curriculum-map.add', $request) }}">@csrf
                        <input type="hidden" name="entity_type" value="axis">
                        <select name="entity_id" required><option value="">Agregar eje…</option>@foreach($catalog['axes'] as $id => $label)<option value="{{ $id }}">{{ $label }}</option>@endforeach</select>
                        <button class="btn outline" style="width:100%;margin-top:6px">Agregar eje</button>
                    </form>
                </div>
            </section>

            <section class="section">
                <h3>Confirmar mapa curricular</h3>
                <div class="muted">Al confirmar guardaremos exactamente las conexiones que elegiste. Si después cambias el tema o la selección, te pediremos revisarlo otra vez.</div>
                <form method="POST" action="{{ route('planning.curriculum-map.confirm', $request) }}">@csrf
                    <button class="btn primary confirm" type="submit">Confirmar estas conexiones</button>
                </form>
            </section>
        </aside>
    </div>
</main>
</body>
</html>
