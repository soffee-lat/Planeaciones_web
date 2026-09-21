<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>Conexiones curriculares · Planeaciones</title>
    <style>
        :root{
            font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            --bg:#f8fafc;--panel:#ffffff;--panel-2:#f8fafc;--text:#0f172a;--muted:#64748b;
            --border:#e2e8f0;--border-strong:#cbd5e1;--accent:#0f766e;--accent-soft:#ccfbf1;
            --ok:#166534;--ok-bg:#f0fdf4;--ok-border:#86efac;--danger:#991b1b;--danger-bg:#fff1f2;--danger-border:#fecaca;
            --warn:#92400e;--warn-bg:#fffbeb;--warn-border:#fde68a;--shadow:0 12px 35px rgba(15,23,42,.06)
        }
        @media (prefers-color-scheme: dark){
            :root{
                --bg:#09090b;--panel:#18181b;--panel-2:#202024;--text:#fafafa;--muted:#a1a1aa;
                --border:#2f2f35;--border-strong:#45454d;--accent:#14b8a6;--accent-soft:#0d2e2b;
                --ok:#86efac;--ok-bg:#10251a;--ok-border:#235d3a;--danger:#fca5a5;--danger-bg:#2b1719;--danger-border:#6b2a31;
                --warn:#fcd34d;--warn-bg:#2a2111;--warn-border:#6b5520;--shadow:0 18px 50px rgba(0,0,0,.28)
            }
        }
        *{box-sizing:border-box} body{margin:0;background:var(--bg);color:var(--text)}
        button,input{font:inherit} a{color:inherit}
        .top{border-bottom:1px solid var(--border);background:var(--panel);padding:15px 22px;display:flex;justify-content:space-between;gap:18px;align-items:center;position:sticky;top:0;z-index:20}
        .brand{font-weight:800}.top-actions{display:flex;gap:8px;flex-wrap:wrap}.wrap{max-width:1180px;margin:0 auto;padding:24px}
        .progress{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}.step{border:1px solid var(--border);background:var(--panel);border-radius:12px;padding:11px 13px;display:flex;gap:10px;align-items:center;color:var(--muted);font-size:13px}.step strong{color:var(--text)}.step.active{border-color:color-mix(in srgb,var(--accent) 55%,var(--border));background:color-mix(in srgb,var(--accent-soft) 70%,var(--panel))}.step.done .num{background:var(--accent);color:#fff}.num{width:26px;height:26px;border-radius:999px;background:var(--panel-2);display:grid;place-items:center;font-weight:800;flex:0 0 auto}
        .hero{border:1px solid color-mix(in srgb,var(--accent) 35%,var(--border));background:linear-gradient(135deg,color-mix(in srgb,var(--accent-soft) 68%,var(--panel)),var(--panel));border-radius:18px;padding:22px;margin-bottom:18px;box-shadow:var(--shadow)}
        .eyebrow{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--accent)}.hero h1{font-size:25px;margin:5px 0 7px}.hero p{margin:5px 0;color:var(--muted);line-height:1.5}.stats{display:flex;gap:8px;flex-wrap:wrap;margin-top:14px}.pill{padding:6px 9px;border-radius:999px;border:1px solid var(--border-strong);background:var(--panel);font-size:12px}
        .notice{padding:12px 14px;border-radius:11px;margin-bottom:15px;font-size:13px;line-height:1.5}.notice.error{background:var(--danger-bg);border:1px solid var(--danger-border);color:var(--danger)}.notice.ok{background:var(--ok-bg);border:1px solid var(--ok-border);color:var(--ok)}.notice.info{background:var(--panel);border:1px solid var(--border);color:var(--muted)}
        .grid{display:grid;grid-template-columns:minmax(0,1fr) 320px;gap:18px}.section{background:var(--panel);border:1px solid var(--border);border-radius:16px;padding:18px;margin-bottom:16px;box-shadow:var(--shadow)}.section h2,.section h3{margin:0}.section h2{font-size:18px}.section h3{font-size:16px}.muted{color:var(--muted);font-size:13px;line-height:1.45}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}
        .item{border:1px solid var(--border);border-radius:13px;padding:14px;margin-top:11px;background:var(--panel-2)}.item.accepted{border-color:var(--ok-border);background:var(--ok-bg)}.item.rejected{border-color:var(--danger-border);background:var(--danger-bg);opacity:.78}.item.pending{border-color:var(--warn-border);background:var(--warn-bg)}.item-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start}.code{font-size:11px;font-weight:800;color:var(--accent);text-transform:uppercase}.title{font-weight:750;margin-top:2px}.field{display:inline-block;margin-top:6px;padding:4px 7px;border-radius:7px;background:color-mix(in srgb,var(--accent-soft) 75%,var(--panel));color:var(--text);font-size:11px}.reason{margin-top:7px;font-size:12px;color:var(--muted)}.status{white-space:nowrap;padding:4px 7px;border-radius:999px;font-size:11px;font-weight:700}.s-accepted{background:var(--ok-bg);color:var(--ok);border:1px solid var(--ok-border)}.s-rejected{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border)}.s-pending{background:var(--warn-bg);color:var(--warn);border:1px solid var(--warn-border)}
        .pda{margin:9px 0 0 18px;padding:10px 12px;border-left:3px solid var(--border-strong);background:var(--panel);border-radius:0 9px 9px 0}.pda.accepted{border-left-color:#22c55e}.pda.rejected{border-left-color:#ef4444;opacity:.75}.pda.pending{border-left-color:#f59e0b}.pda-text{font-size:13px;line-height:1.45}.axes{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:10px}.axes .item{margin-top:0}
        .actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:11px}.btn{border:0;border-radius:9px;padding:9px 12px;font-weight:750;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn:disabled{opacity:.5;cursor:not-allowed}.primary{background:var(--accent);color:#fff}.success{background:var(--ok-bg);color:var(--ok);border:1px solid var(--ok-border)}.danger{background:var(--danger-bg);color:var(--danger);border:1px solid var(--danger-border)}.secondary{background:var(--panel-2);border:1px solid var(--border);color:var(--text)}.outline{background:transparent;border:1px solid var(--border-strong);color:var(--text)}
        .aside{position:sticky;top:82px;align-self:start}.summary-row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}.summary-row:last-child{border-bottom:0}.confirm{width:100%;padding:12px;margin-top:12px}.pending-warning{font-size:12px;color:var(--warn);background:var(--warn-bg);border:1px solid var(--warn-border);padding:10px;border-radius:9px;margin-top:10px}.advanced-link{display:block;margin-top:10px;text-align:center;font-size:12px;color:var(--muted)}
        .catalog-intro{display:flex;justify-content:space-between;gap:16px;align-items:flex-start;margin-bottom:13px}.catalog-grid{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.catalog-box{border:1px solid var(--border);border-radius:13px;padding:12px;background:var(--panel-2)}.catalog-box h3{margin-bottom:4px}.search{width:100%;border:1px solid var(--border-strong);background:var(--panel);color:var(--text);border-radius:9px;padding:9px 10px;margin:9px 0}.choices{max-height:220px;overflow:auto;display:grid;gap:7px;padding-right:3px}.choice{display:flex;gap:9px;align-items:flex-start;padding:8px;border-radius:9px;border:1px solid transparent;cursor:pointer;font-size:12px;line-height:1.35}.choice:hover{border-color:var(--border-strong);background:var(--panel)}.choice.disabled{opacity:.58;cursor:default}.choice input{margin-top:2px}.catalog-actions{display:flex;justify-content:flex-end;gap:10px;margin-top:14px}.empty{padding:18px;border:1px dashed var(--border-strong);border-radius:12px;color:var(--muted);text-align:center;margin-top:10px}
        .no-match{display:grid;grid-template-columns:auto 1fr;gap:12px;align-items:flex-start;padding:14px;border:1px solid var(--border);background:var(--panel);border-radius:12px;margin-bottom:16px}.no-match-icon{width:34px;height:34px;border-radius:10px;background:var(--accent-soft);display:grid;place-items:center;color:var(--accent);font-weight:900}.no-match strong{display:block;margin-bottom:3px}.no-match p{margin:0;color:var(--muted);font-size:13px;line-height:1.45}
        .plan-structure{margin-top:16px;padding:14px;border:1px solid var(--border);border-radius:12px;background:var(--panel)}.plan-project{padding-bottom:10px}.plan-week{padding:10px 0;border-top:1px solid var(--border)}.plan-week-title{font-size:12px;font-weight:800;color:var(--text)}.plan-topic{margin-top:5px;font-size:12px;color:var(--muted)}.plan-topic strong{color:var(--text)}
        .coverage-list{margin-top:10px}.coverage-row{display:flex;align-items:center;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px}.coverage-row:last-child{border-bottom:0}.coverage-ok{color:var(--ok);font-weight:800}.coverage-missing{color:var(--warn);font-weight:800}
        @media(max-width:900px){.grid{grid-template-columns:1fr}.aside{position:static}.catalog-grid{grid-template-columns:1fr}.progress{grid-template-columns:1fr}.wrap{padding:14px}.top{align-items:flex-start}.axes{grid-template-columns:1fr}.item-head,.section-head,.catalog-intro{flex-direction:column}.status{align-self:flex-start}.top-actions{display:none}}
    </style>
</head>
<body>
@php
    $statusLabel = fn (string $status) => match ($status) { 'accepted' => 'Incluido', 'rejected' => 'Descartado', default => 'Por decidir' };
    $statusClass = fn (string $status) => match ($status) { 'accepted' => 's-accepted', 'rejected' => 's-rejected', default => 's-pending' };
    $contentPdas = $pdas->groupBy('curricular_content_id');
    $suggestionCount = count($suggestion['content_ids']) + count($suggestion['pda_ids']) + count($suggestion['axis_ids']);
    $editUrl = \App\Filament\App\Pages\StartPlanning::getUrl() . '?draft=' . $request->id;
@endphp

<header class="top">
    <div>
        <div class="brand">Planeaciones · Docentes</div>
        <div class="muted">{{ $request->group?->name ?? 'Grupo' }} · {{ $request->project }}</div>
    </div>
    <div class="top-actions">
        @if($request->planningWeeks->isNotEmpty())
            <a class="btn outline" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}?draft={{ $request->id }}">Editar periodo y temas</a>
        @endif
        <a class="btn outline" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">Nueva planeación</a>
        <a class="btn secondary" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">Mis planeaciones</a>
    </div>
</header>

<main class="wrap">
    <div class="progress" aria-label="Progreso de la planeación">
        <div class="step done"><span class="num">✓</span><div><strong>Datos</strong><br><span>Grupo, periodo, temas y horario</span></div></div>
        <div class="step active"><span class="num">2</span><div><strong>Conexiones curriculares</strong><br><span>Revisa lo que sí corresponde</span></div></div>
        <div class="step"><span class="num">3</span><div><strong>Generar</strong><br><span>Confirmamos y continuamos</span></div></div>
    </div>

    <section class="hero">
        <div class="eyebrow">Paso 2 de 3</div>
        <h1>Estas son las conexiones que encontramos</h1>
        <p>Revisa únicamente lo necesario. Si la sugerencia automática no encuentra coincidencias, puedes seleccionar varias opciones del catálogo y agregarlas juntas.</p>
        @if($request->planningWeeks->isNotEmpty())
            <p><strong>{{ $request->period_label }}</strong> · {{ $request->planningWeeks->count() }} semana(s)</p>
            <div class="plan-structure">
                @if($request->integrative_project)
                    <div class="plan-project">
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
                            <div class="plan-topic"><strong>{{ $topic->subject?->name ?? 'Materia' }}:</strong> {{ $topic->topic }}</div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @else
            <p><strong>{{ $request->project }}</strong>@if($request->topic) · {{ $request->topic }}@endif</p>
        @endif
        <div class="stats">
            <span class="pill">{{ count($suggestion['content_ids']) }} contenido(s) sugerido(s)</span>
            <span class="pill">{{ count($suggestion['pda_ids']) }} PDA sugerido(s)</span>
            <span class="pill">{{ count($suggestion['formative_field_ids']) }} campo(s) formativo(s)</span>
            <span class="pill">{{ count($suggestion['axis_ids']) }} eje(s)</span>
            <span class="pill">{{ $suggestion['has_strong_match'] ? 'Coincidencia clara' : 'Coincidencia exploratoria' }}</span>
        </div>
    </section>

    @if ($suggestionCount === 0)
        <div class="no-match">
            <div class="no-match-icon">i</div>
            <div>
                <strong>No encontramos una coincidencia automática en este catálogo.</strong>
                <p>Tu tema no está mal. La sugerencia actual funciona por coincidencias de palabras con los contenidos y PDA disponibles. Puedes elegir varias opciones del catálogo de abajo y agregarlas en un solo paso.</p>
            </div>
        </div>
    @endif

    @if ($errors->has('curriculum_map'))
        <div class="notice error">{{ $errors->first('curriculum_map') }}</div>
    @endif
    @if (session('curriculum_map_status'))
        <div class="notice ok">{{ session('curriculum_map_status') }}</div>
    @endif

    <div class="grid">
        <div>
            <section class="section">
                <div class="section-head">
                    <div>
                        <h2>Selección actual</h2>
                        <div class="muted">Contenido, PDA y ejes que formarán parte de la planeación.</div>
                    </div>
                    @if($pending_count > 0)
                        <form method="POST" action="{{ route('planning.curriculum-map.accept-all', $request) }}">
                            @csrf
                            <button class="btn primary" type="submit">Incluir todas las sugerencias</button>
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
                                @if($content->formativeField)<span class="field">{{ $content->formativeField->name }}</span>@endif
                                @if(isset($suggestion['reasons'][$content->id]))<div class="reason">Sugerido porque {{ strtolower($suggestion['reasons'][$content->id]) }}</div>@endif
                                @if($origin === 'teacher_added')<div class="reason">Agregado desde el catálogo.</div>@endif
                            </div>
                            <span class="status {{ $statusClass($status) }}">{{ $statusLabel($status) }}</span>
                        </div>
                        <div class="actions">
                            @if($status !== 'accepted')
                                <form method="POST" action="{{ route('planning.curriculum-map.decision', $request) }}">@csrf
                                    <input type="hidden" name="entity_type" value="content"><input type="hidden" name="entity_id" value="{{ $content->id }}"><input type="hidden" name="decision" value="accept">
                                    <button class="btn success" type="submit">Incluir</button>
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
                    <div class="empty">Todavía no hay contenidos incluidos. Elige uno o varios PDA desde el catálogo; al agregar un PDA incluiremos también su contenido relacionado.</div>
                @endforelse
            </section>

            <section class="section">
                <div class="section-head">
                    <div><h2>Ejes articuladores</h2><div class="muted">Son opcionales. Incluye sólo los que realmente aporten.</div></div>
                </div>
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
                        <div class="empty">No hay ejes incluidos por ahora.</div>
                    @endforelse
                </div>
            </section>

            <section class="section" id="catalogo">
                <div class="catalog-intro">
                    <div>
                        <h2>Agregar desde el catálogo</h2>
                        <div class="muted">Marca varias opciones y agrégalas juntas. No recargaremos la página por cada selección.</div>
                    </div>
                </div>

                <form method="POST" action="{{ route('planning.curriculum-map.add', $request) }}">
                    @csrf
                    <div class="catalog-grid">
                        <div class="catalog-box">
                            <h3>Contenidos</h3>
                            <div class="muted">Puedes elegir más de uno.</div>
                            <input class="search" type="search" placeholder="Buscar contenido…" data-filter="contents-list">
                            <div class="choices" id="contents-list">
                                @forelse($catalog['contents'] as $id => $label)
                                    @php $already = in_array((int) $id, $selected['contents'], true); @endphp
                                    <label class="choice {{ $already ? 'disabled' : '' }}">
                                        <input type="checkbox" name="content_ids[]" value="{{ $id }}" @checked($already) @disabled($already)>
                                        <span>{{ $label }}@if($already) · ya incluido @endif</span>
                                    </label>
                                @empty
                                    <div class="muted">No hay contenidos disponibles para este grado.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="catalog-box">
                            <h3>PDA</h3>
                            <div class="muted">Si eliges un PDA, incluimos automáticamente su contenido.</div>
                            <input class="search" type="search" placeholder="Buscar PDA…" data-filter="pdas-list">
                            <div class="choices" id="pdas-list">
                                @forelse($catalog['pdas'] as $id => $label)
                                    @php $already = in_array((int) $id, $selected['pdas'], true); @endphp
                                    <label class="choice {{ $already ? 'disabled' : '' }}">
                                        <input type="checkbox" name="pda_ids[]" value="{{ $id }}" @checked($already) @disabled($already)>
                                        <span>{{ $label }}@if($already) · ya incluido @endif</span>
                                    </label>
                                @empty
                                    <div class="muted">No hay PDA disponibles para este grado.</div>
                                @endforelse
                            </div>
                        </div>

                        <div class="catalog-box">
                            <h3>Ejes</h3>
                            <div class="muted">Opcional.</div>
                            <input class="search" type="search" placeholder="Buscar eje…" data-filter="axes-list">
                            <div class="choices" id="axes-list">
                                @forelse($catalog['axes'] as $id => $label)
                                    @php $already = in_array((int) $id, $selected['axes'], true); @endphp
                                    <label class="choice {{ $already ? 'disabled' : '' }}">
                                        <input type="checkbox" name="axis_ids[]" value="{{ $id }}" @checked($already) @disabled($already)>
                                        <span>{{ $label }}@if($already) · ya incluido @endif</span>
                                    </label>
                                @empty
                                    <div class="muted">No hay ejes disponibles.</div>
                                @endforelse
                            </div>
                        </div>
                    </div>
                    <div class="catalog-actions">
                        <button class="btn primary" type="submit">Agregar seleccionados</button>
                    </div>
                </form>
            </section>
        </div>

        <aside class="aside">
            <section class="section">
                <h2>Resumen</h2>
                <div class="summary-row"><span>Contenidos</span><strong>{{ count($selected['contents']) }}</strong></div>
                <div class="summary-row"><span>PDA</span><strong>{{ count($selected['pdas']) }}</strong></div>
                <div class="summary-row"><span>Ejes</span><strong>{{ count($selected['axes']) }}</strong></div>
                <div class="summary-row"><span>Por decidir</span><strong>{{ $pending_count }}</strong></div>

                @if($pending_count > 0)
                    <div class="pending-warning">Resuelve primero las sugerencias pendientes. Puedes incluirlas todas de una vez y luego quitar lo que no corresponda.</div>
                @endif
            </section>

            @if(($schedule_field_coverage['required'] ?? []) !== [])
                <section class="section">
                    <h2>Cobertura del horario</h2>
                    <div class="muted">Cada campo oficial no flexible presente en tu horario necesita al menos un contenido y un PDA seleccionados.</div>
                    <div class="coverage-list">
                        @foreach($schedule_field_coverage['required'] as $field)
                            <div class="coverage-row">
                                <span>{{ $field['name'] }} <span class="code">{{ $field['code'] }}</span></span>
                                @if($field['covered'])
                                    <span class="coverage-ok">✓ Cubierto</span>
                                @else
                                    <span class="coverage-missing">⚠ Falta contenido</span>
                                @endif
                            </div>
                        @endforeach
                    </div>
                    @if(($schedule_field_coverage['missing'] ?? []) !== [])
                        <div class="pending-warning">Agrega contenido y PDA de los campos faltantes para que la generación pueda respetar el horario y el currículo.</div>
                    @endif
                </section>
            @endif

            <section class="section">
                <h2>Continuar</h2>
                <form method="POST" action="{{ route('planning.curriculum-map.confirm', $request) }}">
                    @csrf
                    <button class="btn primary confirm" type="submit" @disabled($pending_count > 0 || count($selected['pdas']) === 0 || count($schedule_field_coverage['missing'] ?? []) > 0)>
                        Confirmar y continuar
                    </button>
                </form>
                <div class="muted" style="margin-top:8px">Al confirmar ya no volverás al formulario completo: guardaremos estas conexiones y pasaremos al seguimiento de la planeación.</div>
                <a class="advanced-link" href="{{ $editUrl }}?paso=2">Editar periodo y temas antes de confirmar</a>
            </section>
        </aside>
    </div>
</main>

<script>
    document.querySelectorAll('[data-filter]').forEach((input) => {
        input.addEventListener('input', () => {
            const target = document.getElementById(input.dataset.filter);
            if (!target) return;
            const query = input.value.trim().toLowerCase();
            target.querySelectorAll('.choice').forEach((choice) => {
                choice.hidden = query !== '' && !choice.textContent.toLowerCase().includes(query);
            });
        });
    });
</script>
</body>
</html>
