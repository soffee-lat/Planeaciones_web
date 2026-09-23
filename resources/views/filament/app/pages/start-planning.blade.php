<x-filament-panels::page>
    <style>
        .planning-page {
            --pf-surface: #ffffff;
            --pf-surface-soft: #f8fafc;
            --pf-surface-raised: #ffffff;
            --pf-border: #dbe3ee;
            --pf-border-strong: #b9c7da;
            --pf-text: #172033;
            --pf-text-soft: #475569;
            --pf-text-muted: #64748b;
            --pf-placeholder: #94a3b8;
            --pf-accent: #2563eb;
            --pf-accent-soft: #eff6ff;
            --pf-accent-border: #bfdbfe;
            --pf-danger: #dc2626;
            --pf-warning: #a16207;
            --pf-warning-bg: #fffbeb;
            --pf-warning-border: #fde68a;
            --pf-shadow: 0 10px 30px rgba(15, 23, 42, .06);
        }

        html.dark .planning-page,
        .dark .planning-page {
            --pf-surface: #111c31;
            --pf-surface-soft: #0d1728;
            --pf-surface-raised: #152238;
            --pf-border: #2a3a53;
            --pf-border-strong: #3b4d69;
            --pf-text: #f8fafc;
            --pf-text-soft: #cbd5e1;
            --pf-text-muted: #94a3b8;
            --pf-placeholder: #718198;
            --pf-accent: #7aa2ff;
            --pf-accent-soft: rgba(79, 124, 255, .10);
            --pf-accent-border: rgba(122, 162, 255, .32);
            --pf-danger: #fca5a5;
            --pf-warning: #fbbf24;
            --pf-warning-bg: rgba(245, 158, 11, .10);
            --pf-warning-border: rgba(245, 158, 11, .28);
            --pf-shadow: 0 14px 34px rgba(0, 0, 0, .18);
        }

        .planning-form {
            color: var(--pf-text);
        }

        .planning-label {
            display: block;
            margin-bottom: .45rem;
            color: var(--pf-text);
            font-size: .875rem;
            font-weight: 700;
            line-height: 1.25rem;
        }

        .planning-optional {
            color: var(--pf-text-muted);
            font-weight: 500;
        }

        .planning-help {
            margin-top: .45rem;
            color: var(--pf-text-muted);
            font-size: .78rem;
            line-height: 1.25rem;
        }

        .planning-error {
            margin-top: .4rem;
            color: var(--pf-danger);
            font-size: .78rem;
            font-weight: 600;
        }

        .planning-control {
            display: block;
            width: 100%;
            min-height: 46px;
            border: 1px solid var(--pf-border) !important;
            border-radius: 12px !important;
            background: var(--pf-surface-soft) !important;
            color: var(--pf-text) !important;
            padding: .68rem .85rem !important;
            font-size: .9rem !important;
            line-height: 1.35rem;
            box-shadow: none !important;
            transition: border-color .16s ease, box-shadow .16s ease, background .16s ease;
        }

        textarea.planning-control {
            min-height: 108px;
            resize: vertical;
        }

        .planning-control::placeholder {
            color: var(--pf-placeholder) !important;
            opacity: 1;
        }

        .planning-control:focus {
            outline: none !important;
            border-color: var(--pf-accent) !important;
            background: var(--pf-surface-raised) !important;
            box-shadow: 0 0 0 3px color-mix(in srgb, var(--pf-accent) 18%, transparent) !important;
        }

        .planning-control:disabled {
            cursor: not-allowed;
            opacity: .62;
        }

        .planning-control option {
            color: #172033 !important;
            background: #ffffff !important;
        }

        html.dark .planning-control option,
        .dark .planning-control option {
            color: #f8fafc !important;
            background: #111827 !important;
        }

        .planning-radio-grid {
            display: grid;
            gap: .75rem;
        }

        @media (min-width: 640px) {
            .planning-radio-grid {
                grid-template-columns: repeat(2, minmax(0, 1fr));
            }
        }

        .planning-radio-card {
            display: flex;
            cursor: pointer;
            align-items: flex-start;
            gap: .8rem;
            min-height: 92px;
            border: 1px solid var(--pf-border);
            border-radius: 14px;
            background: var(--pf-surface-soft);
            padding: 1rem;
            transition: border-color .16s ease, background .16s ease, transform .16s ease;
        }

        .planning-radio-card:hover {
            border-color: var(--pf-border-strong);
            background: var(--pf-surface-raised);
        }

        .planning-radio-card:has(input:checked) {
            border-color: var(--pf-accent-border);
            background: var(--pf-accent-soft);
            box-shadow: inset 0 0 0 1px var(--pf-accent-border);
        }

        .planning-radio-card input {
            margin-top: .18rem;
            accent-color: var(--pf-accent);
        }

        .planning-radio-title {
            display: block;
            color: var(--pf-text);
            font-size: .9rem;
            font-weight: 800;
        }

        .planning-radio-copy {
            display: block;
            margin-top: .3rem;
            color: var(--pf-text-muted);
            font-size: .78rem;
            line-height: 1.2rem;
        }

        .planning-project-card,
        .planning-week-card,
        .planning-note-card {
            border: 1px solid var(--pf-border);
            border-radius: 16px;
            background: var(--pf-surface);
            box-shadow: var(--pf-shadow);
        }

        .planning-project-card {
            padding: 1.15rem;
            border-color: var(--pf-accent-border);
            background: linear-gradient(180deg, var(--pf-accent-soft), var(--pf-surface));
        }

        .planning-section-heading {
            color: var(--pf-text);
            font-size: 1rem;
            font-weight: 800;
            line-height: 1.4rem;
        }

        .planning-section-copy {
            margin-top: .3rem;
            color: var(--pf-text-muted);
            font-size: .82rem;
            line-height: 1.3rem;
        }

        .planning-week-card {
            overflow: hidden;
        }

        .planning-week-header {
            display: flex;
            flex-wrap: wrap;
            align-items: flex-start;
            justify-content: space-between;
            gap: .8rem;
            padding: .95rem 1rem;
            border-bottom: 1px solid var(--pf-border);
            background: var(--pf-surface-soft);
        }

        .planning-week-number {
            color: var(--pf-accent);
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .06em;
            text-transform: uppercase;
        }

        .planning-week-title {
            margin-top: .16rem;
            color: var(--pf-text);
            font-size: .95rem;
            font-weight: 800;
        }

        .planning-warning-badge {
            display: inline-flex;
            align-items: center;
            gap: .3rem;
            max-width: 100%;
            border: 1px solid var(--pf-warning-border);
            border-radius: 999px;
            background: var(--pf-warning-bg);
            color: var(--pf-warning);
            padding: .34rem .65rem;
            font-size: .7rem;
            font-weight: 800;
            line-height: 1rem;
        }

        .planning-week-body {
            padding: 1rem;
        }

        .planning-topic-card {
            display: grid;
            gap: .85rem;
            border: 1px solid var(--pf-border);
            border-radius: 13px;
            background: var(--pf-surface-soft);
            padding: .9rem;
        }

        @media (min-width: 768px) {
            .planning-topic-card {
                grid-template-columns: minmax(0, 1.4fr) minmax(210px, .8fr) auto;
                align-items: end;
            }
        }

        .planning-mini-label {
            display: block;
            margin-bottom: .35rem;
            color: var(--pf-text-soft);
            font-size: .7rem;
            font-weight: 800;
            letter-spacing: .035em;
            text-transform: uppercase;
        }

        .planning-remove-btn {
            display: inline-flex;
            min-height: 44px;
            align-items: center;
            justify-content: center;
            border: 1px solid var(--pf-border);
            border-radius: 11px;
            background: transparent;
            color: var(--pf-text-soft);
            padding: 0 .8rem;
            font-size: .82rem;
            font-weight: 700;
            transition: border-color .16s ease, color .16s ease, background .16s ease;
        }

        .planning-remove-btn:hover {
            border-color: color-mix(in srgb, var(--pf-danger) 45%, var(--pf-border));
            background: color-mix(in srgb, var(--pf-danger) 7%, transparent);
            color: var(--pf-danger);
        }

        .planning-add-btn {
            display: inline-flex;
            min-height: 42px;
            align-items: center;
            gap: .4rem;
            border: 1px dashed var(--pf-accent-border);
            border-radius: 11px;
            background: var(--pf-accent-soft);
            color: var(--pf-accent);
            padding: 0 .85rem;
            font-size: .82rem;
            font-weight: 800;
            transition: border-color .16s ease, background .16s ease;
        }

        .planning-add-btn:hover {
            border-color: var(--pf-accent);
            background: color-mix(in srgb, var(--pf-accent) 14%, transparent);
        }

        .planning-note-card {
            padding: 1rem;
        }

        .planning-actions {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .8rem;
            padding-top: .25rem;
        }

        .planning-loading {
            color: var(--pf-text-muted);
            font-size: .82rem;
        }

        .planning-side-copy {
            color: var(--pf-text-soft);
        }

        .planning-side-copy strong {
            color: var(--pf-text);
        }

        .planning-side-muted {
            color: var(--pf-text-muted);
        }

        .planning-topics-heading {
            display: flex;
            flex-wrap: wrap;
            align-items: end;
            justify-content: space-between;
            gap: .5rem;
        }

        .planning-calendar {
            overflow: hidden;
            border: 1px solid var(--pf-border);
            border-radius: 16px;
            background: var(--pf-surface);
            box-shadow: var(--pf-shadow);
        }

        .planning-calendar-header {
            display: grid;
            grid-template-columns: 44px minmax(0, 1fr) 44px;
            align-items: center;
            gap: .5rem;
            padding: .9rem 1rem;
            border-bottom: 1px solid var(--pf-border);
            background: var(--pf-surface-soft);
        }

        .planning-calendar-title {
            text-align: center;
            color: var(--pf-text);
            font-size: .95rem;
            font-weight: 800;
        }

        .planning-calendar-nav {
            display: inline-grid;
            width: 40px;
            height: 40px;
            place-items: center;
            border: 1px solid var(--pf-border);
            border-radius: 10px;
            background: var(--pf-surface);
            color: var(--pf-text);
            font-size: 1.2rem;
            font-weight: 800;
            cursor: pointer;
        }

        .planning-calendar-nav:hover:not(:disabled) {
            border-color: var(--pf-accent-border);
            background: var(--pf-accent-soft);
            color: var(--pf-accent);
        }

        .planning-calendar-nav:disabled {
            opacity: .3;
            cursor: default;
        }

        .planning-calendar-weekdays,
        .planning-calendar-grid {
            display: grid;
            grid-template-columns: repeat(7, minmax(0, 1fr));
        }

        .planning-calendar-weekday {
            padding: .55rem .25rem;
            border-bottom: 1px solid var(--pf-border);
            color: var(--pf-text-muted);
            text-align: center;
            font-size: .68rem;
            font-weight: 800;
            text-transform: uppercase;
        }

        .planning-calendar-day {
            position: relative;
            min-height: 58px;
            border: 0;
            border-right: 1px solid var(--pf-border);
            border-bottom: 1px solid var(--pf-border);
            background: var(--pf-surface);
            color: var(--pf-text);
            font-size: .82rem;
            font-weight: 700;
            cursor: pointer;
            transition: background .12s ease, color .12s ease, box-shadow .12s ease;
        }

        .planning-calendar-day:nth-child(7n) {
            border-right: 0;
        }

        .planning-calendar-day:hover:not(:disabled) {
            z-index: 1;
            background: var(--pf-accent-soft);
            box-shadow: inset 0 0 0 2px var(--pf-accent-border);
        }

        .planning-calendar-day.is-outside {
            color: var(--pf-text-muted);
            background: var(--pf-surface-soft);
        }

        .planning-calendar-day.is-weekend,
        .planning-calendar-day:disabled {
            color: var(--pf-text-muted);
            background: color-mix(in srgb, var(--pf-surface-soft) 72%, transparent);
            cursor: default;
            opacity: .58;
        }

        .planning-calendar-day.is-occupied:not(.is-selected) {
            background: var(--pf-warning-bg);
            color: var(--pf-warning);
        }

        .planning-calendar-day.is-selected {
            z-index: 2;
            background: var(--pf-accent);
            color: #fff;
            box-shadow: inset 0 0 0 1px color-mix(in srgb, var(--pf-accent) 82%, #000);
        }

        .planning-calendar-day-number {
            display: inline-grid;
            min-width: 28px;
            min-height: 28px;
            place-items: center;
            border-radius: 999px;
        }

        .planning-calendar-dot {
            position: absolute;
            right: 7px;
            top: 7px;
            width: 7px;
            height: 7px;
            border-radius: 999px;
            background: var(--pf-warning);
        }

        .planning-calendar-day.is-selected .planning-calendar-dot {
            background: #fff;
        }

        .planning-calendar-legend {
            display: flex;
            flex-wrap: wrap;
            gap: .75rem 1rem;
            padding: .75rem 1rem;
            border-top: 1px solid var(--pf-border);
            color: var(--pf-text-muted);
            font-size: .72rem;
        }

        .planning-calendar-legend span {
            display: inline-flex;
            align-items: center;
            gap: .35rem;
        }

        .planning-legend-swatch {
            width: 10px;
            height: 10px;
            border-radius: 3px;
            border: 1px solid var(--pf-border);
            background: var(--pf-surface);
        }

        .planning-legend-swatch.selected {
            border-color: var(--pf-accent);
            background: var(--pf-accent);
        }

        .planning-legend-swatch.occupied {
            border-color: var(--pf-warning-border);
            background: var(--pf-warning-bg);
        }

        .planning-calendar-selection {
            margin-top: .8rem;
            padding: .85rem 1rem;
            border: 1px solid var(--pf-accent-border);
            border-radius: 12px;
            background: var(--pf-accent-soft);
            color: var(--pf-text-soft);
            font-size: .82rem;
            line-height: 1.35rem;
        }

        .planning-calendar-selection strong {
            color: var(--pf-text);
        }

        .planning-calendar-selection.is-occupied {
            border-color: var(--pf-warning-border);
            background: var(--pf-warning-bg);
        }

        .planning-calendar-empty {
            padding: 1.25rem;
            border: 1px dashed var(--pf-border-strong);
            border-radius: 14px;
            background: var(--pf-surface-soft);
            color: var(--pf-text-muted);
            text-align: center;
            font-size: .82rem;
        }

        @media (max-width: 640px) {
            .planning-calendar-day {
                min-height: 48px;
                font-size: .76rem;
            }

            .planning-calendar-dot {
                right: 4px;
                top: 5px;
            }
        }
    </style>

    @php($periodOptions = $period_type === 'month' ? $this->periodOptions() : [])
    @php($weekCalendar = $period_type === 'week' ? $this->weekCalendar() : null)
    @php($subjects = $this->subjectOptions())
    @php($selectedGroup = $this->selectedGroup())
    @php($selectedLevel = $selectedGroup?->curriculumVersion?->curriculum?->educational_level)
    @php($selectedLevelLabel = \App\Enums\EducationalLevel::labelFor($selectedLevel))

    <div class="planning-page mx-auto grid w-full max-w-6xl gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">¿Qué periodo vas a planear?</x-slot>
                <x-slot name="description">Elige el grupo y después marca la semana en el calendario o selecciona un mes. Las fechas se calculan automáticamente.</x-slot>

                <form wire:submit="start" class="planning-form space-y-6">
                    <div>
                        <label class="planning-label">Grupo</label>
                        <select
                            wire:model.live="group_id"
                            @disabled($draft_id)
                            class="planning-control"
                        >
                            <option value="">Selecciona un grupo…</option>
                            @foreach ($groups as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('group_id') <p class="planning-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="planning-label">Formato del documento</label>
                        <select
                            wire:model="format_version_id"
                            class="planning-control"
                        >
                            @foreach ($formats as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="planning-help">
                            El formato general de Planeaciones queda seleccionado por defecto. Si tienes un formato institucional disponible, puedes elegirlo aquí. Esta elección define la salida final del documento y no cambia la generación pedagógica.
                        </p>
                        @error('format_version_id') <p class="planning-error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="planning-label">Tipo de planeación</label>
                        <div class="planning-radio-grid">
                            <label class="planning-radio-card">
                                <input type="radio" value="week" wire:model.live="period_type">
                                <span>
                                    <strong class="planning-radio-title">Por semana</strong>
                                    <span class="planning-radio-copy">Marca cualquier día de la semana escolar; seleccionaremos automáticamente de lunes a viernes.</span>
                                </span>
                            </label>

                            <label class="planning-radio-card">
                                <input type="radio" value="month" wire:model.live="period_type">
                                <span>
                                    <strong class="planning-radio-title">Por mes</strong>
                                    <span class="planning-radio-copy">El mes se divide automáticamente en semanas completas o parciales.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div>
                        @if ($period_type === 'week')
                            <label class="planning-label">Semana a planear</label>

                            @if(!$group_id)
                                <div class="planning-calendar-empty">
                                    Selecciona primero un grupo para ver su calendario escolar.
                                </div>
                            @elseif($weekCalendar && $weekCalendar['days'] !== [])
                                <div class="planning-calendar">
                                    <div class="planning-calendar-header">
                                        <button
                                            type="button"
                                            wire:click="previousCalendarMonth"
                                            class="planning-calendar-nav"
                                            aria-label="Mes anterior"
                                            @disabled(!$weekCalendar['can_previous'])
                                        >‹</button>

                                        <div class="planning-calendar-title">{{ $weekCalendar['label'] }}</div>

                                        <button
                                            type="button"
                                            wire:click="nextCalendarMonth"
                                            class="planning-calendar-nav"
                                            aria-label="Mes siguiente"
                                            @disabled(!$weekCalendar['can_next'])
                                        >›</button>
                                    </div>

                                    <div class="planning-calendar-weekdays" aria-hidden="true">
                                        @foreach (['Lun', 'Mar', 'Mié', 'Jue', 'Vie', 'Sáb', 'Dom'] as $weekday)
                                            <div class="planning-calendar-weekday">{{ $weekday }}</div>
                                        @endforeach
                                    </div>

                                    <div class="planning-calendar-grid">
                                        @foreach ($weekCalendar['days'] as $day)
                                            <button
                                                type="button"
                                                wire:key="planning-calendar-{{ $day['date'] }}"
                                                @if($day['available']) wire:click="selectCalendarDay('{{ $day['date'] }}')" @endif
                                                @disabled(!$day['available'])
                                                title="{{ $day['occupied'] ? 'Esta semana ya tiene una planeación' : ($day['available'] ? 'Seleccionar esta semana' : '') }}"
                                                class="planning-calendar-day
                                                    {{ !$day['in_month'] ? 'is-outside' : '' }}
                                                    {{ $day['weekend'] ? 'is-weekend' : '' }}
                                                    {{ $day['occupied'] ? 'is-occupied' : '' }}
                                                    {{ $day['selected'] ? 'is-selected' : '' }}"
                                            >
                                                <span class="planning-calendar-day-number">{{ $day['day'] }}</span>
                                                @if($day['occupied'])
                                                    <span class="planning-calendar-dot" aria-hidden="true"></span>
                                                @endif
                                            </button>
                                        @endforeach
                                    </div>

                                    <div class="planning-calendar-legend">
                                        <span><i class="planning-legend-swatch selected"></i> Semana seleccionada</span>
                                        <span><i class="planning-legend-swatch occupied"></i> Ya tiene planeación</span>
                                    </div>
                                </div>

                                @if($weekCalendar['selected_week_label'])
                                    <div class="planning-calendar-selection {{ $weekCalendar['selected_week_occupied'] ? 'is-occupied' : '' }}">
                                        <strong>Semana seleccionada: {{ $weekCalendar['selected_week_label'] }}</strong>
                                        @if($weekCalendar['selected_week_occupied'])
                                            <div>⚠ Esta semana se cruza con una planeación existente del mismo grupo.</div>
                                        @else
                                            <div>Disponible para crear la planeación semanal.</div>
                                        @endif
                                    </div>
                                @else
                                    <p class="planning-help">
                                        Da clic en cualquier día de lunes a viernes. Resaltaremos automáticamente toda la semana escolar.
                                    </p>
                                @endif
                            @else
                                <div class="planning-calendar-empty">
                                    No encontramos semanas disponibles para el ciclo escolar de este grupo.
                                </div>
                            @endif
                        @else
                            <label class="planning-label">Mes a planear</label>
                            <select
                                wire:model.live="period_key"
                                @disabled(!$group_id)
                                class="planning-control"
                            >
                                <option value="">
                                    {{ $group_id ? 'Selecciona un mes…' : 'Primero selecciona un grupo' }}
                                </option>
                                @foreach ($periodOptions as $key => $label)
                                    <option value="{{ $key }}">{{ $label }}</option>
                                @endforeach
                            </select>

                            @if($group_id)
                                <p class="planning-help">
                                    Los meses marcados con ⚠ contienen periodos que ya tienen una planeación del mismo grupo.
                                </p>
                            @endif
                        @endif

                        @error('period_key') <p class="planning-error">{{ $message }}</p> @enderror
                    </div>

                    @if ($period_type === 'month' && $period_key)
                        <section class="planning-project-card">
                            <div class="mb-5">
                                <h3 class="planning-section-heading">
                                    Proyecto integrador del mes
                                    <span class="planning-optional">(opcional)</span>
                                </h3>
                                <p class="planning-section-copy">
                                    Úsalo cuando varias áreas o materias compartirán un mismo hilo conductor. No sustituye los temas propios de cada área.
                                </p>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="planning-label">Nombre del proyecto integrador</label>
                                    <input
                                        type="text"
                                        wire:model="integrative_project"
                                        maxlength="255"
                                        placeholder="Ej. Cuidemos nuestra comunidad"
                                        class="planning-control"
                                    >
                                    @error('integrative_project') <p class="planning-error">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="planning-label">
                                        Propósito <span class="planning-optional">(opcional)</span>
                                    </label>
                                    <textarea
                                        wire:model="integrative_project_purpose"
                                        rows="3"
                                        placeholder="Describe brevemente qué conecta el proyecto y qué busca lograr."
                                        class="planning-control"
                                    ></textarea>
                                    @error('integrative_project_purpose') <p class="planning-error">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </section>
                    @endif

                    @if ($weeks !== [])
                        <section class="space-y-4">
                            <div class="planning-topics-heading">
                                <div>
                                    <h3 class="planning-section-heading">Temas por semana y área</h3>
                                    <p class="planning-section-copy">
                                        Asigna cada tema a su área o materia principal. Las áreas sin tema propio podrán participar de forma transversal cuando sea pertinente.
                                    </p>
                                </div>
                            </div>

                            @foreach ($weeks as $weekIndex => $week)
                                <article
                                    wire:key="planning-week-{{ $week['sequence'] }}-{{ $week['starts_on'] }}"
                                    class="planning-week-card"
                                >
                                    <div class="planning-week-header">
                                        <div>
                                            <div class="planning-week-number">Semana {{ $week['sequence'] }}</div>
                                            <div class="planning-week-title">{{ $week['label'] }}</div>
                                        </div>

                                        @if (!empty($week['occupied']))
                                            <span class="planning-warning-badge">
                                                ⚠ Esta semana ya tiene otra planeación
                                            </span>
                                        @endif
                                    </div>

                                    <div class="planning-week-body space-y-3">
                                        @foreach (($week['topics'] ?? []) as $topicIndex => $topic)
                                            <div
                                                wire:key="planning-topic-{{ $weekIndex }}-{{ $topicIndex }}"
                                                class="planning-topic-card"
                                            >
                                                <div>
                                                    <label class="planning-mini-label">Tema</label>
                                                    <input
                                                        type="text"
                                                        wire:model="weeks.{{ $weekIndex }}.topics.{{ $topicIndex }}.topic"
                                                        maxlength="255"
                                                        placeholder="Ej. Números hasta 10,000"
                                                        class="planning-control"
                                                    >
                                                    @error("weeks.$weekIndex.topics.$topicIndex.topic")
                                                        <p class="planning-error">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                <div>
                                                    <label class="planning-mini-label">Área o materia principal</label>
                                                    <select
                                                        wire:model="weeks.{{ $weekIndex }}.topics.{{ $topicIndex }}.group_subject_id"
                                                        class="planning-control"
                                                    >
                                                        <option value="">Selecciona…</option>
                                                        @foreach ($subjects as $subjectId => $subjectName)
                                                            <option value="{{ $subjectId }}">{{ $subjectName }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error("weeks.$weekIndex.topics.$topicIndex.group_subject_id")
                                                        <p class="planning-error">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                <div>
                                                    <button
                                                        type="button"
                                                        wire:click="removeTopic({{ $weekIndex }}, {{ $topicIndex }})"
                                                        class="planning-remove-btn"
                                                        title="Quitar tema"
                                                    >
                                                        Quitar
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach

                                        @error("weeks.$weekIndex.topics")
                                            <p class="planning-error">{{ $message }}</p>
                                        @enderror

                                        <button
                                            type="button"
                                            wire:click="addTopic({{ $weekIndex }})"
                                            class="planning-add-btn"
                                        >
                                            <span aria-hidden="true">＋</span>
                                            Agregar otro tema
                                        </button>
                                    </div>
                                </article>
                            @endforeach
                        </section>
                    @endif

                    @if ($period_key)
                        <section class="planning-note-card">
                            <label class="planning-label">
                                Algo que debamos considerar
                                <span class="planning-optional">(opcional)</span>
                            </label>
                            <textarea
                                wire:model="context_note"
                                rows="4"
                                placeholder="Ej. Habrá una actividad especial el viernes, quiero reforzar lectura, considerar material del libro…"
                                class="planning-control"
                            ></textarea>
                            <p class="planning-help">
                                Agrega aquí eventos, materiales, restricciones o indicaciones que deban respetarse durante la planeación.
                            </p>
                            @error('context_note') <p class="planning-error">{{ $message }}</p> @enderror
                        </section>

                        <div class="planning-actions">
                            <x-filament::button
                                type="submit"
                                size="lg"
                                icon="heroicon-o-arrow-right"
                                wire:loading.attr="disabled"
                            >
                                {{ $draft_id ? 'Guardar cambios y revisar conexiones' : 'Continuar a revisión curricular' }}
                            </x-filament::button>

                            <span class="planning-loading" wire:loading>
                                Preparando la estructura…
                            </span>
                        </div>
                    @endif
                </form>
            </x-filament::section>
        </div>

        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Grupo</x-slot>

                @if ($selectedGroup)
                    <div class="planning-side-copy space-y-2 text-sm">
                        <p><strong>{{ $selectedGroup->name }}</strong></p>
                        <div style="margin:.65rem 0;padding:.7rem .8rem;border:1px solid var(--pf-accent-border);border-radius:.7rem;background:var(--pf-accent-soft)">
                            <strong>{{ $selectedLevelLabel }}</strong><br>
                            <span style="color:var(--pf-text-muted)">
                                Esta planeación se calibrará específicamente para {{ $selectedGroup->grade?->name ?? 'el grado configurado' }}.
                            </span>
                        </div>
                        <p>Grado: {{ $selectedGroup->grade?->name ?? 'Configurado en tu grupo' }}</p>
                        <p>Ciclo escolar: {{ $selectedGroup->school_year }}</p>

                        @if ($selectedGroup->profile?->session_minutes)
                            <p>Sesión habitual: {{ $selectedGroup->profile->session_minutes }} min</p>
                        @endif
                    </div>
                @else
                    <p class="planning-side-muted text-sm">
                        Al seleccionar un grupo reutilizaremos su currículo, perfil pedagógico, áreas/campos y horario.
                    </p>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Cómo se usará</x-slot>

                <div class="planning-side-copy space-y-3 text-sm">
                    <p><strong>1.</strong> El periodo define las semanas reales de la planeación.</p>
                    <p><strong>2.</strong> Cada tema queda ligado a un área o materia principal.</p>
                    <p><strong>3.</strong> El horario determina en qué bloques se desarrolla.</p>
                    <p><strong>4.</strong> Las áreas sin tema asignado pueden reforzar transversalmente los temas activos.</p>
                    <p><strong>5.</strong> Si es mensual, el sistema conserva la progresión entre semanas.</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Después</x-slot>

                <ol class="planning-side-copy space-y-3 text-sm">
                    @if($draft_id)
                        <li><strong>Ahora.</strong> Guardaremos los cambios sobre esta misma planeación.</li>
                    @endif
                    <li><strong>1.</strong> Revisas contenidos, PDA, campos y ejes relacionados.</li>
                    <li><strong>2.</strong> Confirmas las conexiones curriculares.</li>
                    <li><strong>3.</strong> Revisas el resumen final y confirmas la planeación.</li>
                    <li><strong>4.</strong> Cuando esté activada, inicias la generación respetando semanas, áreas/campos, horario y transversalidad.</li>
                    <li><strong>5.</strong> Cuando el contenido esté aprobado, eliges el formato de exportación. El estándar es la opción recomendada.</li>
                </ol>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
