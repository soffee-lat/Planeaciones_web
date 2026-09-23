<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <meta name="color-scheme" content="light dark">
    <title>Resumen de planeación · Planeaciones</title>
    <style>
        :root{
            font-family:Inter,ui-sans-serif,system-ui,-apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif;
            --bg:#f8fafc;--panel:#fff;--panel-2:#f8fafc;--text:#0f172a;--muted:#64748b;--border:#e2e8f0;
            --accent:#0f766e;--accent-soft:#ccfbf1;--ok:#166534;--ok-bg:#f0fdf4;--ok-border:#86efac;
            --warn:#92400e;--warn-bg:#fffbeb;--warn-border:#fde68a;--danger:#991b1b;--danger-bg:#fff1f2;--danger-border:#fecaca;
            --shadow:0 12px 35px rgba(15,23,42,.06)
        }
        @media(prefers-color-scheme:dark){
            :root{
                --bg:#09090b;--panel:#18181b;--panel-2:#202024;--text:#fafafa;--muted:#a1a1aa;--border:#2f2f35;
                --accent:#14b8a6;--accent-soft:#0d2e2b;--ok:#86efac;--ok-bg:#10251a;--ok-border:#235d3a;
                --warn:#fcd34d;--warn-bg:#2a2111;--warn-border:#6b5520;--danger:#fca5a5;--danger-bg:#2b1719;--danger-border:#6b2a31;
                --shadow:0 18px 50px rgba(0,0,0,.28)
            }
        }
        *{box-sizing:border-box}body{margin:0;background:var(--bg);color:var(--text)}a{color:inherit}.top{border-bottom:1px solid var(--border);background:var(--panel);padding:15px 22px;display:flex;justify-content:space-between;gap:18px;align-items:center;position:sticky;top:0;z-index:20}.brand{font-weight:800}.top-actions{display:flex;gap:8px;flex-wrap:wrap}.wrap{max-width:1120px;margin:0 auto;padding:24px}.progress{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:18px}.step{border:1px solid var(--border);background:var(--panel);border-radius:12px;padding:11px 13px;display:flex;gap:10px;align-items:center;color:var(--muted);font-size:13px}.step strong{color:var(--text)}.step.done .num,.step.active .num{background:var(--accent);color:#fff}.step.active{border-color:color-mix(in srgb,var(--accent) 55%,var(--border));background:color-mix(in srgb,var(--accent-soft) 70%,var(--panel))}.num{width:26px;height:26px;border-radius:999px;background:var(--panel-2);display:grid;place-items:center;font-weight:800;flex:0 0 auto}.hero,.section{background:var(--panel);border:1px solid var(--border);border-radius:16px;box-shadow:var(--shadow)}.hero{padding:22px;margin-bottom:18px;background:linear-gradient(135deg,color-mix(in srgb,var(--accent-soft) 68%,var(--panel)),var(--panel))}.eyebrow{font-size:12px;font-weight:800;letter-spacing:.04em;text-transform:uppercase;color:var(--accent)}h1{font-size:25px;margin:5px 0 7px}.muted{color:var(--muted);font-size:13px;line-height:1.5}.grid{display:grid;grid-template-columns:minmax(0,1fr) 330px;gap:18px}.section{padding:18px;margin-bottom:16px}.section h2{margin:0 0 12px;font-size:18px}.row{display:grid;grid-template-columns:180px minmax(0,1fr);gap:14px;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px}.row:last-child{border-bottom:0}.label{color:var(--muted);font-weight:700}.value{font-weight:650}.week{padding:12px 0;border-top:1px solid var(--border)}.week:first-of-type{border-top:0}.week-title{font-weight:800;font-size:13px}.topic{margin-top:6px;font-size:13px;color:var(--muted)}.topic strong{color:var(--text)}.stats{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}.stat{border:1px solid var(--border);border-radius:11px;background:var(--panel-2);padding:12px;text-align:center}.stat strong{display:block;font-size:22px}.stat span{font-size:11px;color:var(--muted)}.coverage{display:grid;gap:8px}.coverage-row{display:flex;justify-content:space-between;gap:12px;align-items:center;padding:9px 0;border-bottom:1px solid var(--border);font-size:13px}.coverage-row:last-child{border-bottom:0}.ok{color:var(--ok);font-weight:800}.warn{color:var(--warn);font-weight:800}.details{margin-top:12px;border-top:1px solid var(--border);padding-top:12px}.details summary{cursor:pointer;font-weight:800;font-size:13px}.detail-item{margin-top:9px;padding:10px;border:1px solid var(--border);border-radius:10px;background:var(--panel-2);font-size:12px;line-height:1.45}.code{font-size:11px;font-weight:800;color:var(--accent);text-transform:uppercase}.aside{position:sticky;top:82px;align-self:start}.actions{display:grid;gap:9px}.btn{border:0;border-radius:9px;padding:11px 13px;font-weight:800;cursor:pointer;text-decoration:none;text-align:center;display:inline-flex;align-items:center;justify-content:center}.primary{background:var(--accent);color:#fff}.outline{background:transparent;border:1px solid var(--border);color:var(--text)}.notice{padding:12px 14px;border-radius:11px;margin-bottom:15px;font-size:13px;line-height:1.5}.notice.error{background:var(--danger-bg);border:1px solid var(--danger-border);color:var(--danger)}.notice.ok{background:var(--ok-bg);border:1px solid var(--ok-border);color:var(--ok)}.confirm-note{margin-top:10px;padding:10px;border:1px solid var(--warn-border);background:var(--warn-bg);border-radius:10px;color:var(--warn);font-size:12px;line-height:1.45}.commercial{font-size:13px;line-height:1.5}.commercial p{margin:7px 0}@media(max-width:900px){.grid{grid-template-columns:1fr}.aside{position:static}.progress{grid-template-columns:1fr}.wrap{padding:14px}.top{align-items:flex-start}.top-actions{display:none}.stats{grid-template-columns:1fr}.row{grid-template-columns:1fr;gap:4px}}
    </style>
</head>
<body>
<header class="top">
    <div>
        <div class="brand">Planeaciones · Docentes</div>
        <div class="muted">{{ $request->group?->name ?? 'Grupo' }} · Resumen final</div>
    </div>
    <div class="top-actions">
        <a class="btn outline" href="{{ $edit_structure_url }}">Editar periodo y temas</a>
        <a class="btn outline" href="{{ $edit_curriculum_url }}">Editar conexiones curriculares</a>
        <a class="btn outline" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">Mis planeaciones</a>
    </div>
</header>

<main class="wrap">
    <div class="progress" aria-label="Progreso de la planeación">
        <div class="step done"><span class="num">✓</span><div><strong>Periodo y temas</strong><br><span>Estructura definida</span></div></div>
        <div class="step done"><span class="num">✓</span><div><strong>Conexiones curriculares</strong><br><span>Mapa confirmado</span></div></div>
        <div class="step active"><span class="num">3</span><div><strong>Resumen y confirmación</strong><br><span>Revisa antes de enviar</span></div></div>
    </div>

    <section class="hero">
        <div class="eyebrow">Paso 3 de 3</div>
        <h1>Revisa tu planeación antes de confirmarla</h1>
        <div class="muted">Aquí ya no necesitas volver a capturar nada. Si algo no corresponde, edita el periodo y los temas o regresa a las conexiones curriculares.</div>
    </section>

    @if($errors->has('planning_review'))
        <div class="notice error">{{ $errors->first('planning_review') }}</div>
    @endif
    @if(session('curriculum_map_status'))
        <div class="notice ok">{{ session('curriculum_map_status') }}</div>
    @endif

    <div class="grid">
        <div>
            <section class="section">
                <h2>Planeación</h2>
                <div class="row"><div class="label">Grupo</div><div class="value">{{ $request->group?->name ?? '—' }}</div></div>
                <div class="row"><div class="label">Nivel educativo</div><div class="value">{{ \App\Enums\EducationalLevel::labelFor($request->group?->curriculumVersion?->curriculum?->educational_level) }}</div></div>
                <div class="row"><div class="label">Grado</div><div class="value">{{ $request->group?->grade?->name ?? $request->grade?->name ?? '—' }}</div></div>
                <div class="row"><div class="label">Periodo</div><div class="value">{{ $request->period_label ?: (($request->starts_on?->format('d/m/Y') ?? '—') . ' → ' . ($request->ends_on?->format('d/m/Y') ?? '—')) }}</div></div>
                <div class="row"><div class="label">Formato de salida</div><div class="value">{{ $request->formatVersion?->format?->name ?? 'Formato general de Planeaciones' }}</div></div>
                <div class="row"><div class="label">Tema o proyecto</div><div class="value">{{ $request->integrative_project ?: $request->project }}</div></div>
                @if($request->integrative_project_purpose)
                    <div class="row"><div class="label">Propósito</div><div class="value">{{ $request->integrative_project_purpose }}</div></div>
                @endif
                @if($request->comments)
                    <div class="row"><div class="label">Consideraciones</div><div class="value">{{ $request->comments }}</div></div>
                @endif
            </section>

            @if($request->planningWeeks->isNotEmpty())
                <section class="section">
                    <h2>Temas por semana y área</h2>
                    @foreach($request->planningWeeks as $week)
                        <div class="week">
                            <div class="week-title">Semana {{ $week->sequence }} · {{ $week->label }}</div>
                            @foreach($week->topics as $topic)
                                <div class="topic"><strong>{{ $topic->subject?->name ?? 'Área' }}:</strong> {{ $topic->topic }}@if($topic->notes) · {{ $topic->notes }}@endif</div>
                            @endforeach
                        </div>
                    @endforeach
                </section>
            @endif

            <section class="section">
                <h2>Conexiones curriculares</h2>
                <div class="stats">
                    <div class="stat"><strong>{{ $request->contents->count() }}</strong><span>Contenidos</span></div>
                    <div class="stat"><strong>{{ $request->pdas->count() }}</strong><span>PDA</span></div>
                    <div class="stat"><strong>{{ $request->articulatingAxes->count() }}</strong><span>Ejes</span></div>
                </div>

                @if($coverage !== [])
                    <div class="details">
                        <strong style="font-size:13px">Cobertura del horario</strong>
                        <div class="coverage">
                            @foreach($coverage as $field)
                                <div class="coverage-row">
                                    <span>{{ $field['name'] }} <span class="code">{{ $field['code'] }}</span></span>
                                    <span class="{{ $field['covered'] ? 'ok' : 'warn' }}">{{ $field['covered'] ? '✓ Cubierto' : '⚠ Falta cobertura' }}</span>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endif

                <details class="details">
                    <summary>Ver contenidos seleccionados</summary>
                    @foreach($request->contents as $content)
                        <div class="detail-item">
                            <div class="code">{{ $content->code }}</div>
                            <strong>{{ $content->title }}</strong>
                            @if($content->formativeField)<div class="muted">{{ $content->formativeField->name }}</div>@endif
                        </div>
                    @endforeach
                </details>

                <details class="details">
                    <summary>Ver PDA seleccionados</summary>
                    @foreach($request->pdas as $pda)
                        <div class="detail-item">
                            <div class="code">{{ $pda->code }}</div>
                            {{ $pda->full_text }}
                        </div>
                    @endforeach
                </details>

                @if($request->articulatingAxes->isNotEmpty())
                    <details class="details">
                        <summary>Ver ejes articuladores</summary>
                        @foreach($request->articulatingAxes as $axis)
                            <div class="detail-item"><span class="code">{{ $axis->code }}</span> · {{ $axis->name }}</div>
                        @endforeach
                    </details>
                @endif
            </section>
        </div>

        <aside class="aside">
            <section class="section">
                <h2>Tu plan</h2>
                <div class="commercial">
                    @include('filament.app.pages.commercial-summary', ['summary' => $commercial_summary])
                </div>
            </section>

            <section class="section">
                <h2>Confirmar</h2>
                <div class="actions">
                    <form method="POST" action="{{ route('planning.review.confirm', $request) }}">
                        @csrf
                        <button class="btn primary" style="width:100%" type="submit">Confirmar planeación</button>
                    </form>
                    <a class="btn outline" href="{{ $edit_curriculum_url }}">Editar conexiones curriculares</a>
                    <a class="btn outline" href="{{ $edit_structure_url }}">Editar periodo y temas</a>
                </div>
                <div class="confirm-note">Al confirmar se congela una versión de estos datos. Si tu plan tiene unidades disponibles, se reservarán para esta solicitud; la generación no comienza hasta que la inicies desde el seguimiento.</div>
            </section>
        </aside>
    </div>
</main>
</body>
</html>
