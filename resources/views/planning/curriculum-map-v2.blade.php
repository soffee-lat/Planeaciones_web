<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <script>
        (() => {
            const savedTheme = localStorage.getItem('theme');
            const resolvedTheme = savedTheme === 'dark' || savedTheme === 'light'
                ? savedTheme
                : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
            document.documentElement.dataset.theme = resolvedTheme;
            document.documentElement.style.colorScheme = resolvedTheme;
        })();
    </script>
    <title>Conexiones curriculares · Planeaciones</title>
    <style>
        :root{
            font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            --bg:#f8fafc;--panel:#fff;--soft:#f8fafc;--text:#0f172a;--muted:#64748b;
            --border:#e2e8f0;--accent:#0f766e;--accent-soft:#ccfbf1;
            --ok:#166534;--ok-bg:#f0fdf4;--ok-border:#86efac;
            --warn:#92400e;--warn-bg:#fffbeb;--warn-border:#fde68a;
            --danger:#991b1b;--danger-bg:#fff1f2;--danger-border:#fecaca;
            --shadow:0 12px 34px rgba(15,23,42,.06)
        }
        :root[data-theme="dark"]{
            --bg:#09090b;--panel:#18181b;--soft:#202024;--text:#fafafa;--muted:#a1a1aa;
            --border:#34343a;--accent:#14b8a6;--accent-soft:#0d2e2b;
            --ok:#86efac;--ok-bg:#10251a;--ok-border:#235d3a;
            --warn:#fcd34d;--warn-bg:#2a2111;--warn-border:#6b5520;
            --danger:#fca5a5;--danger-bg:#2b1719;--danger-border:#6b2a31;--shadow:0 18px 48px rgba(0,0,0,.25)
        }
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text)}button,input{font:inherit}a{color:inherit}
        .top{position:sticky;top:0;z-index:20;border-bottom:1px solid var(--border);background:var(--panel);padding:14px 22px;display:flex;justify-content:space-between;gap:16px;align-items:center}.brand{font-weight:850}.muted{color:var(--muted);font-size:13px;line-height:1.5}
        .wrap{max-width:1180px;margin:0 auto;padding:24px}.progress{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}.step{border:1px solid var(--border);background:var(--panel);border-radius:12px;padding:11px 13px;display:flex;gap:10px;align-items:center;color:var(--muted);font-size:13px}.step strong{color:var(--text)}.step.active{border-color:var(--accent);background:color-mix(in srgb,var(--accent-soft) 65%,var(--panel))}.num{width:26px;height:26px;border-radius:999px;background:var(--soft);display:grid;place-items:center;font-weight:800;flex:0 0 auto}.step.done .num{background:var(--accent);color:white}
        .hero,.section{background:var(--panel);border:1px solid var(--border);border-radius:17px;box-shadow:var(--shadow)}.hero{padding:22px;margin-bottom:18px;background:linear-gradient(135deg,color-mix(in srgb,var(--accent-soft) 58%,var(--panel)),var(--panel))}.eyebrow{font-size:12px;font-weight:850;text-transform:uppercase;letter-spacing:.04em;color:var(--accent)}h1{font-size:25px;margin:5px 0 7px}.hero p{margin:5px 0;color:var(--muted);line-height:1.5}.plan{margin-top:15px;padding:13px;border:1px solid var(--border);border-radius:12px;background:var(--panel)}.week{padding:9px 0;border-top:1px solid var(--border)}.week:first-child{border-top:0}.week-title{font-size:12px;font-weight:850}.topic{font-size:12px;color:var(--muted);margin-top:4px}.topic strong{color:var(--text)}.stats{display:flex;flex-wrap:wrap;gap:7px;margin-top:13px}.pill{border:1px solid var(--border);background:var(--panel);border-radius:999px;padding:6px 9px;font-size:12px}
        .notice{border-radius:12px;padding:13px 14px;margin-bottom:15px;font-size:13px;line-height:1.5}.notice.info{border:1px solid var(--border);background:var(--panel)}.notice.ok{border:1px solid var(--ok-border);background:var(--ok-bg);color:var(--ok)}.notice.warn{border:1px solid var(--warn-border);background:var(--warn-bg);color:var(--warn)}.notice.error{border:1px solid var(--danger-border);background:var(--danger-bg);color:var(--danger)}
        .grid{display:grid;grid-template-columns:minmax(0,1fr) 310px;gap:18px}.section{padding:18px;margin-bottom:16px}.section-head{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;margin-bottom:12px}.section h2{font-size:18px;margin:0}.section h3{font-size:15px;margin:0}.aside{position:sticky;top:82px;align-self:start}
        .item{border:1px solid var(--border);background:var(--soft);border-radius:13px;padding:13px;margin-top:10px}.item.accepted{border-color:var(--ok-border);background:var(--ok-bg)}.item.rejected{opacity:.72;border-color:var(--danger-border);background:var(--danger-bg)}.item.pending{border-color:var(--warn-border);background:var(--warn-bg)}.item-head{display:flex;justify-content:space-between;gap:10px;align-items:flex-start}.code{font-size:11px;font-weight:850;color:var(--accent);text-transform:uppercase}.title{font-weight:780;margin-top:2px}.field{display:inline-block;margin-top:5px;padding:3px 6px;border-radius:7px;background:var(--accent-soft);font-size:11px}.reason{font-size:12px;color:var(--muted);margin-top:6px}.status{font-size:11px;font-weight:800;border-radius:999px;padding:4px 7px;white-space:nowrap}.s-accepted{color:var(--ok);border:1px solid var(--ok-border);background:var(--ok-bg)}.s-rejected{color:var(--danger);border:1px solid var(--danger-border);background:var(--danger-bg)}.s-pending{color:var(--warn);border:1px solid var(--warn-border);background:var(--warn-bg)}.pda{margin:9px 0 0 17px;padding:10px 11px;border-left:3px solid var(--border);background:var(--panel);border-radius:0 9px 9px 0}.pda-text{font-size:13px;line-height:1.45}.actions{display:flex;gap:7px;flex-wrap:wrap;margin-top:10px}
        .btn{border:0;border-radius:9px;padding:9px 12px;font-weight:780;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;justify-content:center}.btn:disabled{opacity:.5;cursor:not-allowed}.primary{background:var(--accent);color:#fff}.secondary{border:1px solid var(--border);background:var(--soft);color:var(--text)}.success{border:1px solid var(--ok-border);background:var(--ok-bg);color:var(--ok)}.danger{border:1px solid var(--danger-border);background:var(--danger-bg);color:var(--danger)}
        .optional-box{border:1px solid var(--accent);background:color-mix(in srgb,var(--accent-soft) 30%,var(--panel));border-radius:13px;padding:13px;margin-top:10px}.optional-head{display:flex;justify-content:space-between;gap:12px}.optional-options{display:grid;gap:8px;margin-top:10px}.option{display:grid;grid-template-columns:auto 1fr;gap:10px;align-items:flex-start;border:1px solid var(--border);border-radius:10px;padding:10px;background:var(--panel);cursor:pointer}.option input{margin-top:3px}.option strong{font-size:13px}.option p{margin:4px 0 0;font-size:12px;color:var(--muted);line-height:1.45}
        .catalog{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:11px}.catalog-box{border:1px solid var(--border);background:var(--soft);border-radius:12px;padding:11px}.choices{max-height:230px;overflow:auto;display:grid;gap:7px;margin-top:8px}.choice{display:flex;gap:8px;align-items:flex-start;font-size:12px;line-height:1.35;padding:7px;border-radius:8px}.choice:hover{background:var(--panel)}.choice.disabled{opacity:.55}.catalog-actions{display:flex;justify-content:flex-end;margin-top:12px}
        .empty{border:1px dashed var(--border);border-radius:11px;padding:14px;color:var(--muted);font-size:13px;line-height:1.5;margin-top:10px}.summary-row{display:flex;justify-content:space-between;gap:12px;padding:8px 0;border-bottom:1px solid var(--border);font-size:13px}.summary-row:last-child{border-bottom:0}.coverage-row{display:flex;justify-content:space-between;gap:10px;padding:8px 0;border-bottom:1px solid var(--border);font-size:12px}.coverage-row:last-child{border-bottom:0}.coverage-ok{color:var(--ok);font-weight:800}.coverage-optional{color:var(--muted);font-weight:750}.confirm{width:100%;padding:12px;margin-top:10px}
        @media(max-width:900px){.grid{grid-template-columns:1fr}.aside{position:static}.catalog{grid-template-columns:1fr}.progress{grid-template-columns:1fr}.wrap{padding:14px}.section-head,.item-head,.optional-head{flex-direction:column}.top{align-items:flex-start}}
    </style>
</head>
<body>
@php
    $statusLabel = fn (string $status) => match ($status) { 'accepted' => 'Incluido', 'rejected' => 'Descartado', default => 'Por decidir' };
    $statusClass = fn (string $status) => match ($status) { 'accepted' => 's-accepted', 'rejected' => 's-rejected', default => 's-pending' };
    $contentPdas = $pdas->groupBy('curricular_content_id');
    $suggestionCount = count($suggestion['content_ids']) + count($suggestion['pda_ids']) + count($suggestion['axis_ids']);
    $educationalLevelLabel = \App\Enums\EducationalLevel::labelFor($request->group?->curriculumVersion?->curriculum?->educational_level);
    $editUrl = \App\Filament\App\Pages\StartPlanning::getUrl() . '?draft=' . $request->id;
    $optionalFields = $schedule_field_coverage['missing'] ?? [];
@endphp

<header class="top">
    <div>
        <div class="brand">Planeaciones · Docentes</div>
        <div class="muted">{{ $request->group?->name ?? 'Grupo' }} · {{ $educationalLevelLabel }} · {{ $request->group?->grade?->name ?? 'Grado' }}</div>
    </div>
    <a class="btn secondary" href="{{ $editUrl }}">Editar periodo y temas</a>
</header>

<main class="wrap">
    <div class="progress" aria-label="Progreso de la planeación">
        <div class="step done"><span class="num">✓</span><div><strong>Periodo y temas</strong><br><span>Grupo, semanas, materias y horario</span></div></div>
        <div class="step active"><span class="num">2</span><div><strong>Conexiones curriculares</strong><br><span>Revisa sólo las que tengan sentido</span></div></div>
        <div class="step"><span class="num">3</span><div><strong>Confirmación</strong><br><span>Resumen final antes de generar</span></div></div>
    </div>

    <section class="hero">
        <div class="eyebrow">Paso 2 de 3</div>
        <h1>Estas son las conexiones que encontramos</h1>
        <p>Los contenidos y PDA son una ayuda curricular. Incluye únicamente los que realmente correspondan a tus temas; si no existe una relación clara, puedes continuar sin asociarlos.</p>
        @if($request->planningWeeks->isNotEmpty())
            <p><strong>{{ $request->period_label }}</strong> · {{ $request->planningWeeks->count() }} semana(s)</p>
            <div class="plan">
                @if($request->integrative_project)
                    <div class="week">
                        <div class="code">Proyecto integrador</div>
                        <div class="title">{{ $request->integrative_project }}</div>
                        @if($request->integrative_project_purpose)<div class="reason">{{ $request->integrative_project_purpose }}</div>@endif
                    </div>
                @endif
                @foreach($request->planningWeeks as $week)
                    <div class="week">
                        <div class="week-title">Semana {{ $week->sequence }} · {{ $week->label }}</div>
                        @foreach($week->topics as $topic)
                            <div class="topic"><strong>{{ $topic->subject?->name ?? 'Materia' }}:</strong> {{ $topic->topic }}</div>
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
            <span class="pill">{{ count($suggestion['axis_ids']) }} eje(s)</span>
            <span class="pill">{{ $suggestion['has_strong_match'] ? 'Coincidencia clara' : 'Sin coincidencia fuerte' }}</span>
        </div>
    </section>

    @if($suggestionCount === 0)
        <div class="notice info">
            <strong>No encontramos una coincidencia curricular clara con tus temas.</strong><br>
            Esto no impide generar la planeación. Puedes buscar manualmente si conoces una referencia adecuada o continuar sin asociar un PDA.
        </div>
    @endif

    @if($errors->has('curriculum_map'))
        <div class="notice error">{{ $errors->first('curriculum_map') }}</div>
    @endif
    @if(session('curriculum_map_status'))
        <div class="notice ok">{{ session('curriculum_map_status') }}</div>
    @endif

    <div class="grid">
        <div>
            @if($optionalFields !== [])
                <section class="section">
                    <div class="section-head">
                        <div>
                            <h2>Referencia curricular opcional</h2>
                            <div class="muted">Tu horario contiene campos formativos que todavía no tienen una referencia seleccionada. Sólo agrega una cuando realmente coincida con el tema que vas a trabajar.</div>
                        </div>
                    </div>

                    @foreach($optionalFields as $field)
                        @php $fieldOptions = $schedule_field_options[$field['code']] ?? []; @endphp
                        <div class="optional-box">
                            <div class="optional-head">
                                <div>
                                    <div class="title">{{ $field['name'] }}</div>
                                    <div class="code">{{ $field['code'] }}</div>
                                </div>
                                <span class="status s-pending">Opcional</span>
                            </div>

                            @if($fieldOptions !== [])
                                <div class="muted" style="margin-top:7px">Encontramos estas coincidencias temáticas. Elige una sólo si describe lo que realmente vas a trabajar.</div>
                                <form method="POST" action="{{ route('planning.curriculum-map.add', $request) }}">
                                    @csrf
                                    <div class="optional-options">
                                        @foreach($fieldOptions as $option)
                                            <label class="option">
                                                <input type="checkbox" name="pda_ids[]" value="{{ $option['pda_id'] }}">
                                                <span>
                                                    <strong>{{ $option['content_title'] }}</strong>
                                                    <p>{{ $option['pda_text'] }}</p>
                                                    @if(!empty($option['reason']))<p>{{ $option['reason'] }}</p>@endif
                                                </span>
                                            </label>
                                        @endforeach
                                    </div>
                                    <div class="actions"><button class="btn primary" type="submit">Agregar selección</button></div>
                                </form>
                            @else
                                <div class="empty"><strong>No encontramos un PDA claramente relacionado con tus temas.</strong><br>Puedes continuar sin asociar uno. No seleccionaremos una referencia sólo para llenar el formato.</div>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="section">
                <div class="section-head">
                    <div>
                        <h2>Selección actual</h2>
                        <div class="muted">Revisa las sugerencias y conserva únicamente las que sí correspondan al trabajo del grupo.</div>
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
                                @if(isset($suggestion['reasons'][$content->id]))<div class="reason">{{ $suggestion['reasons'][$content->id] }}</div>@endif
                                @if($origin === 'teacher_added')<div class="reason">Agregado manualmente por el docente.</div>@endif
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
                            <div class="pda">
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
                    <div class="empty">No hay contenidos o PDA incluidos. Esto es válido cuando no existe una relación curricular clara con los temas capturados.</div>
                @endforelse
            </section>

            <section class="section">
                <div class="section-head"><div><h2>Ejes articuladores</h2><div class="muted">También son opcionales. Conserva únicamente los que aporten a la planeación.</div></div></div>
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
            </section>

            <section class="section">
                <div class="section-head"><div><h2>Buscar manualmente en el catálogo</h2><div class="muted">Úsalo sólo si reconoces una referencia curricular adecuada que no apareció en las sugerencias.</div></div></div>
                <form method="POST" action="{{ route('planning.curriculum-map.add', $request) }}">
                    @csrf
                    <div class="catalog">
                        <div class="catalog-box">
                            <h3>Contenidos</h3>
                            <div class="choices">
                                @forelse($catalog['contents'] as $id => $label)
                                    @php $already = in_array((int) $id, $selected['contents'], true); @endphp
                                    <label class="choice {{ $already ? 'disabled' : '' }}"><input type="checkbox" name="content_ids[]" value="{{ $id }}" @checked($already) @disabled($already)><span>{{ $label }}</span></label>
                                @empty<div class="muted">No hay contenidos disponibles.</div>@endforelse
                            </div>
                        </div>
                        <div class="catalog-box">
                            <h3>PDA</h3>
                            <div class="choices">
                                @forelse($catalog['pdas'] as $id => $label)
                                    @php $already = in_array((int) $id, $selected['pdas'], true); @endphp
                                    <label class="choice {{ $already ? 'disabled' : '' }}"><input type="checkbox" name="pda_ids[]" value="{{ $id }}" @checked($already) @disabled($already)><span>{{ $label }}</span></label>
                                @empty<div class="muted">No hay PDA disponibles.</div>@endforelse
                            </div>
                        </div>
                        <div class="catalog-box">
                            <h3>Ejes</h3>
                            <div class="choices">
                                @forelse($catalog['axes'] as $id => $label)
                                    @php $already = in_array((int) $id, $selected['axes'], true); @endphp
                                    <label class="choice {{ $already ? 'disabled' : '' }}"><input type="checkbox" name="axis_ids[]" value="{{ $id }}" @checked($already) @disabled($already)><span>{{ $label }}</span></label>
                                @empty<div class="muted">No hay ejes disponibles.</div>@endforelse
                            </div>
                        </div>
                    </div>
                    <div class="catalog-actions"><button class="btn primary" type="submit">Agregar seleccionados</button></div>
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
            </section>

            @if(($schedule_field_coverage['required'] ?? []) !== [])
                <section class="section">
                    <h2>Relación con el horario</h2>
                    <div class="muted">Una referencia curricular sólo se exige cuando realmente fue seleccionada. Los bloques institucionales pueden quedar sin PDA NEM.</div>
                    @foreach($schedule_field_coverage['required'] as $field)
                        <div class="coverage-row">
                            <span>{{ $field['name'] }}</span>
                            @if($field['covered'])
                                <span class="coverage-ok">✓ Relacionado</span>
                            @else
                                <span class="coverage-optional">Sin referencia · opcional</span>
                            @endif
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="section">
                <h2>Continuar</h2>
                @if($pending_count > 0)
                    <div class="notice warn" style="margin-top:10px"><strong>Revisa las sugerencias pendientes.</strong><br>Acepta o descarta cada sugerencia antes de continuar.</div>
                @else
                    <div class="notice ok" style="margin-top:10px"><strong>Todo listo.</strong><br>Puedes continuar aunque no hayas seleccionado un PDA cuando no existe una relación pertinente.</div>
                @endif
                <form method="POST" action="{{ route('planning.curriculum-map.confirm', $request) }}">
                    @csrf
                    <button class="btn primary confirm" type="submit" @disabled($pending_count > 0)>{{ $pending_count > 0 ? 'Revisa lo pendiente para continuar' : 'Confirmar y continuar' }}</button>
                </form>
                <div class="muted" style="margin-top:8px">Guardaremos las conexiones que sí elegiste y te mostraremos el resumen final.</div>
            </section>
        </aside>
    </div>
</main>
</body>
</html>
