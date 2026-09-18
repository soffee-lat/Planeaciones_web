<x-filament-panels::page>
    <div
        x-data="scheduleEditor({
            hasSchedule: @js($hasSchedule),
            dayStart: @js($dayStartsAt),
            dayEnd: @js($dayEndsAt),
            defaultDuration: @js($defaultBlockMinutes),
            fieldOptions: @js($fieldOptions),
            initialBlocks: @js($blocks)
        })"
        class="space-y-5"
    >
        <style>
            [x-cloak] { display: none !important; }

            .schedule-onboarding-wrap {
                max-width: 980px;
                margin: 1.25rem auto 0;
            }

            .schedule-onboarding-card {
                overflow: hidden;
                border: 1px solid #e5e7eb;
                border-radius: 24px;
                background: #ffffff;
                box-shadow:
                    0 1px 2px rgb(15 23 42 / .04),
                    0 12px 36px rgb(15 23 42 / .08);
                color: #0f172a;
            }

            .schedule-onboarding-hero {
                position: relative;
                padding: 30px 32px 26px;
                border-bottom: 1px solid #eef2f7;
                background:
                    radial-gradient(circle at 92% 18%, rgb(59 130 246 / .16), transparent 28%),
                    linear-gradient(135deg, #f8fbff 0%, #ffffff 62%);
            }

            .schedule-onboarding-kicker {
                display: inline-flex;
                align-items: center;
                gap: .5rem;
                margin-bottom: .85rem;
                padding: .38rem .7rem;
                border-radius: 999px;
                background: #eff6ff;
                color: #2563eb;
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .055em;
                text-transform: uppercase;
            }

            .schedule-onboarding-title {
                margin: 0;
                max-width: 680px;
                color: #0f172a;
                font-size: clamp(1.65rem, 3vw, 2.15rem);
                line-height: 1.1;
                font-weight: 800;
                letter-spacing: -.035em;
            }

            .schedule-onboarding-copy {
                max-width: 680px;
                margin-top: .75rem;
                color: #64748b;
                font-size: .95rem;
                line-height: 1.55;
            }

            .schedule-onboarding-body {
                padding: 28px 32px 30px;
            }

            .schedule-step-label {
                display: flex;
                align-items: center;
                gap: .65rem;
                margin-bottom: 14px;
                color: #334155;
                font-size: .8rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }

            .schedule-step-number {
                display: inline-grid;
                width: 28px;
                height: 28px;
                place-items: center;
                border-radius: 9px;
                background: #eff6ff;
                color: #2563eb;
                font-size: .75rem;
                font-weight: 900;
            }

            .schedule-time-grid {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 14px;
            }

            .schedule-time-card {
                display: grid;
                grid-template-columns: 42px minmax(0, 1fr);
                align-items: center;
                gap: 12px;
                min-height: 82px;
                padding: 14px 16px;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                background: #f8fafc;
                transition: border-color .15s ease, box-shadow .15s ease, transform .15s ease;
            }

            .schedule-time-card:focus-within {
                border-color: #60a5fa;
                box-shadow: 0 0 0 4px rgb(59 130 246 / .10);
                transform: translateY(-1px);
            }

            .schedule-time-icon {
                display: grid;
                width: 42px;
                height: 42px;
                place-items: center;
                border-radius: 12px;
                background: #ffffff;
                border: 1px solid #e2e8f0;
                color: #2563eb;
                box-shadow: 0 2px 8px rgb(15 23 42 / .05);
            }

            .schedule-time-meta {
                display: block;
                margin-bottom: 2px;
                color: #64748b;
                font-size: .72rem;
                font-weight: 800;
                letter-spacing: .04em;
                text-transform: uppercase;
            }

            .schedule-time-input {
                width: 100%;
                padding: 0;
                border: 0;
                outline: 0;
                background: transparent;
                color: #0f172a;
                font-size: 1.15rem;
                font-weight: 800;
                box-shadow: none;
            }

            .schedule-recess-card {
                display: flex;
                align-items: flex-start;
                justify-content: space-between;
                gap: 18px;
                margin-top: 16px;
                padding: 17px 18px;
                border: 1px solid #e2e8f0;
                border-radius: 16px;
                background: #ffffff;
            }

            .schedule-recess-title {
                color: #0f172a;
                font-size: .92rem;
                font-weight: 750;
            }

            .schedule-recess-copy {
                margin-top: 3px;
                color: #64748b;
                font-size: .78rem;
                line-height: 1.45;
            }

            .schedule-switch {
                position: relative;
                width: 46px;
                height: 26px;
                flex: 0 0 auto;
            }

            .schedule-switch input {
                position: absolute;
                opacity: 0;
                pointer-events: none;
            }

            .schedule-switch-track {
                position: absolute;
                inset: 0;
                border-radius: 999px;
                background: #cbd5e1;
                cursor: pointer;
                transition: background .2s ease;
            }

            .schedule-switch-track::after {
                content: '';
                position: absolute;
                top: 3px;
                left: 3px;
                width: 20px;
                height: 20px;
                border-radius: 50%;
                background: #fff;
                box-shadow: 0 1px 4px rgb(15 23 42 / .22);
                transition: transform .2s ease;
            }

            .schedule-switch input:checked + .schedule-switch-track {
                background: #2563eb;
            }

            .schedule-switch input:checked + .schedule-switch-track::after {
                transform: translateX(20px);
            }

            .schedule-recess-times {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 12px;
                margin-top: 12px;
                padding: 14px;
                border-radius: 14px;
                background: #f8fafc;
            }

            .schedule-mini-label {
                display: block;
                margin-bottom: 6px;
                color: #64748b;
                font-size: .7rem;
                font-weight: 800;
                text-transform: uppercase;
                letter-spacing: .04em;
            }

            .schedule-mini-input {
                width: 100%;
                padding: 9px 11px;
                border: 1px solid #dbe2ea;
                border-radius: 10px;
                background: #fff;
                color: #0f172a;
                font-weight: 700;
                outline: none;
            }

            .schedule-onboarding-footer {
                display: flex;
                align-items: center;
                justify-content: space-between;
                gap: 16px;
                margin-top: 24px;
                padding-top: 22px;
                border-top: 1px solid #eef2f7;
            }

            .schedule-footer-note {
                color: #64748b;
                font-size: .78rem;
                line-height: 1.45;
            }

            .schedule-primary-action {
                min-width: 190px;
                padding: 12px 18px;
                border: 0;
                border-radius: 12px;
                background: linear-gradient(135deg, #2563eb, #3b82f6);
                color: #fff;
                font-size: .9rem;
                font-weight: 800;
                cursor: pointer;
                box-shadow: 0 8px 22px rgb(37 99 235 / .26);
                transition: transform .15s ease, box-shadow .15s ease;
            }

            .schedule-primary-action:hover {
                transform: translateY(-1px);
                box-shadow: 0 10px 28px rgb(37 99 235 / .32);
            }

            .schedule-secondary-action {
                padding: 11px 16px;
                border: 1px solid #dbe2ea;
                border-radius: 12px;
                background: transparent;
                color: #475569;
                font-size: .85rem;
                font-weight: 700;
                cursor: pointer;
            }

            .schedule-error {
                margin-top: 12px;
                padding: 10px 12px;
                border-radius: 10px;
                background: #fef2f2;
                color: #b91c1c;
                font-size: .8rem;
                font-weight: 700;
            }

            html.dark .schedule-onboarding-card,
            .dark .schedule-onboarding-card {
                border-color: rgb(255 255 255 / .09);
                background: #111827;
                color: #f8fafc;
                box-shadow: 0 18px 46px rgb(0 0 0 / .28);
            }

            html.dark .schedule-onboarding-hero,
            .dark .schedule-onboarding-hero {
                border-bottom-color: rgb(255 255 255 / .08);
                background:
                    radial-gradient(circle at 92% 18%, rgb(59 130 246 / .20), transparent 28%),
                    linear-gradient(135deg, #111827 0%, #0f172a 100%);
            }

            html.dark .schedule-onboarding-title,
            .dark .schedule-onboarding-title,
            html.dark .schedule-recess-title,
            .dark .schedule-recess-title {
                color: #f8fafc;
            }

            html.dark .schedule-onboarding-copy,
            .dark .schedule-onboarding-copy,
            html.dark .schedule-recess-copy,
            .dark .schedule-recess-copy,
            html.dark .schedule-footer-note,
            .dark .schedule-footer-note,
            html.dark .schedule-time-meta,
            .dark .schedule-time-meta,
            html.dark .schedule-mini-label,
            .dark .schedule-mini-label {
                color: #94a3b8;
            }

            html.dark .schedule-step-label,
            .dark .schedule-step-label {
                color: #cbd5e1;
            }

            html.dark .schedule-time-card,
            .dark .schedule-time-card {
                border-color: rgb(255 255 255 / .09);
                background: #0b1220;
            }

            html.dark .schedule-time-icon,
            .dark .schedule-time-icon {
                border-color: rgb(255 255 255 / .09);
                background: #111827;
                color: #60a5fa;
                box-shadow: none;
            }

            html.dark .schedule-time-input,
            .dark .schedule-time-input,
            html.dark .schedule-mini-input,
            .dark .schedule-mini-input {
                color: #f8fafc;
                color-scheme: dark;
            }

            html.dark .schedule-recess-card,
            .dark .schedule-recess-card {
                border-color: rgb(255 255 255 / .09);
                background: #0b1220;
            }

            html.dark .schedule-recess-times,
            .dark .schedule-recess-times {
                background: #111827;
            }

            html.dark .schedule-mini-input,
            .dark .schedule-mini-input {
                border-color: rgb(255 255 255 / .10);
                background: #0b1220;
            }

            html.dark .schedule-onboarding-footer,
            .dark .schedule-onboarding-footer {
                border-top-color: rgb(255 255 255 / .08);
            }

            html.dark .schedule-secondary-action,
            .dark .schedule-secondary-action {
                border-color: rgb(255 255 255 / .10);
                color: #cbd5e1;
            }

            @media (max-width: 720px) {
                .schedule-onboarding-wrap {
                    margin-top: .5rem;
                }
                .schedule-onboarding-hero,
                .schedule-onboarding-body {
                    padding-left: 18px;
                    padding-right: 18px;
                }
                .schedule-time-grid,
                .schedule-recess-times {
                    grid-template-columns: 1fr;
                }
                .schedule-onboarding-footer {
                    align-items: stretch;
                    flex-direction: column;
                }
                .schedule-primary-action,
                .schedule-secondary-action {
                    width: 100%;
                }
            }
            .schedule-canvas {
                background-image: repeating-linear-gradient(
                    to bottom,
                    transparent 0,
                    transparent calc(var(--half-hour) - 1px),
                    rgb(148 163 184 / .18) calc(var(--half-hour) - 1px),
                    rgb(148 163 184 / .18) var(--half-hour)
                );
            }
            .dark .schedule-canvas {
                background-image: repeating-linear-gradient(
                    to bottom,
                    transparent 0,
                    transparent calc(var(--half-hour) - 1px),
                    rgb(255 255 255 / .08) calc(var(--half-hour) - 1px),
                    rgb(255 255 255 / .08) var(--half-hour)
                );
            }
        </style>

        <div x-show="setupOpen" x-cloak class="schedule-onboarding-wrap">
            <section class="schedule-onboarding-card">
                <div class="schedule-onboarding-hero">
                    <div class="schedule-onboarding-kicker">
                        <svg width="14" height="14" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                            <path d="M7 3v3M17 3v3M4 9h16M6 5h12a2 2 0 0 1 2 2v12a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V7a2 2 0 0 1 2-2Z" stroke="currentColor" stroke-width="2" stroke-linecap="round"/>
                        </svg>
                        Configuración inicial
                    </div>

                    <h2 class="schedule-onboarding-title" x-text="firstSetup ? 'Armemos tu semana escolar' : 'Ajusta tu jornada'"></h2>
                    <p class="schedule-onboarding-copy">
                        Sólo necesitamos tu hora de entrada y salida. Después podrás acomodar clases, talleres y espacios especiales directamente sobre el horario.
                    </p>
                </div>

                <div class="schedule-onboarding-body">
                    <div class="schedule-step-label">
                        <span class="schedule-step-number">1</span>
                        Define tu jornada
                    </div>

                    <div class="schedule-time-grid">
                        <label class="schedule-time-card">
                            <span class="schedule-time-icon">
                                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span>
                                <span class="schedule-time-meta">Entrada</span>
                                <input x-model="dayStart" type="time" class="schedule-time-input" />
                            </span>
                        </label>

                        <label class="schedule-time-card">
                            <span class="schedule-time-icon">
                                <svg width="21" height="21" viewBox="0 0 24 24" fill="none" aria-hidden="true">
                                    <circle cx="12" cy="12" r="8.5" stroke="currentColor" stroke-width="1.8"/>
                                    <path d="M12 7.5V12l3 2" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/>
                                </svg>
                            </span>
                            <span>
                                <span class="schedule-time-meta">Salida</span>
                                <input x-model="dayEnd" type="time" class="schedule-time-input" />
                            </span>
                        </label>
                    </div>

                    <div x-show="firstSetup" class="schedule-recess-card">
                        <div style="flex:1;min-width:0">
                            <div class="schedule-step-label" style="margin-bottom:6px">
                                <span class="schedule-step-number">2</span>
                                Recreo
                            </div>
                            <div class="schedule-recess-title">¿Tu grupo tiene un recreo habitual?</div>
                            <div class="schedule-recess-copy">Puedes agregarlo de una vez de lunes a viernes. Después podrás cambiarlo por día.</div>

                            <div x-show="recessEnabled" x-cloak class="schedule-recess-times">
                                <label>
                                    <span class="schedule-mini-label">Empieza</span>
                                    <input x-model="recessStart" type="time" class="schedule-mini-input" />
                                </label>
                                <label>
                                    <span class="schedule-mini-label">Termina</span>
                                    <input x-model="recessEnd" type="time" class="schedule-mini-input" />
                                </label>
                            </div>
                        </div>

                        <label class="schedule-switch" aria-label="Agregar recreo">
                            <input x-model="recessEnabled" type="checkbox" />
                            <span class="schedule-switch-track"></span>
                        </label>
                    </div>

                    <p x-show="setupError" x-cloak x-text="setupError" class="schedule-error"></p>

                    <div class="schedule-onboarding-footer">
                        <div class="schedule-footer-note">
                            <strong style="color:inherit">No necesitas definir materias todavía.</strong><br>
                            Primero creamos la estructura de tu semana y después acomodas cada bloque visualmente.
                        </div>

                        <div style="display:flex;gap:10px;align-items:center">
                            <button
                                x-show="!firstSetup"
                                type="button"
                                class="schedule-secondary-action"
                                x-on:click="setupOpen = false"
                            >Cancelar</button>

                            <button
                                type="button"
                                class="schedule-primary-action"
                                x-on:click="applySetup()"
                            >
                                <span x-text="firstSetup ? 'Crear mi horario' : 'Aplicar jornada'"></span>
                                <span aria-hidden="true" style="margin-left:8px">→</span>
                            </button>
                        </div>
                    </div>
                </div>
            </section>
        </div>

        <template x-if="started">
            <div class="space-y-4">
                <section class="rounded-2xl border border-gray-200 bg-white p-4 shadow-sm dark:border-white/10 dark:bg-gray-900">
                    <div class="flex flex-wrap items-center gap-3">
                        <div class="mr-auto">
                            <div class="text-xs font-semibold uppercase tracking-wide text-gray-500 dark:text-gray-400">Jornada habitual</div>
                            <div class="mt-1 flex items-center gap-2 text-base font-semibold text-gray-950 dark:text-white">
                                <span x-text="dayStart"></span>
                                <span class="text-gray-400">→</span>
                                <span x-text="dayEnd"></span>
                                <span class="ml-2 rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-600 dark:bg-white/10 dark:text-gray-300">
                                    <span x-text="planableCount()"></span> bloques planeables
                                </span>
                            </div>
                        </div>

                        <span
                            x-show="dirty"
                            x-cloak
                            class="rounded-full bg-warning-50 px-3 py-1.5 text-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-300"
                        >Cambios sin guardar</span>

                        <x-filament::button color="gray" type="button" x-on:click="setupOpen = true">
                            Ajustar jornada
                        </x-filament::button>
                        <x-filament::button type="button" x-on:click="save($wire)">
                            Guardar horario
                        </x-filament::button>
                    </div>

                    <div class="mt-4 flex items-start gap-3 rounded-xl bg-primary-50/70 px-4 py-3 text-sm text-primary-800 dark:bg-primary-500/10 dark:text-primary-200">
                        <span class="mt-0.5 text-lg">＋</span>
                        <div>
                            <strong>Toca cualquier espacio del horario para agregar una clase.</strong>
                            <span class="block text-xs opacity-80">La hora se toma del lugar donde toques y puedes ajustarla antes de guardar.</span>
                        </div>
                    </div>
                </section>

                <div class="lg:hidden">
                    <div class="flex gap-2 overflow-x-auto pb-2">
                        <template x-for="day in days" :key="day.value">
                            <button
                                type="button"
                                x-on:click="activeDay = day.value"
                                class="shrink-0 rounded-full px-4 py-2 text-sm font-semibold transition"
                                :class="activeDay === day.value
                                    ? 'bg-primary-600 text-white shadow-sm'
                                    : 'bg-white text-gray-700 ring-1 ring-gray-200 dark:bg-gray-900 dark:text-gray-200 dark:ring-white/10'"
                                x-text="day.label"
                            ></button>
                        </template>
                    </div>

                    <div class="overflow-hidden rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900">
                        <div class="grid grid-cols-[64px_1fr] border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <div></div>
                            <div class="px-4 py-3 text-center font-semibold text-gray-900 dark:text-white" x-text="dayName(activeDay)"></div>
                        </div>
                        <div class="relative grid grid-cols-[64px_1fr]" :style="'height:' + gridHeight() + 'px'">
                            <div class="relative border-r border-gray-200 bg-gray-50/60 dark:border-white/10 dark:bg-white/[0.03]">
                                <template x-for="mark in timeMarks()" :key="mark.minutes">
                                    <span
                                        class="absolute right-2 -translate-y-1/2 text-[11px] font-medium text-gray-500 dark:text-gray-400"
                                        :style="'top:' + mark.top + 'px'"
                                        x-text="mark.label"
                                    ></span>
                                </template>
                            </div>
                            <div
                                class="schedule-canvas relative cursor-crosshair"
                                :style="'--half-hour:' + halfHourPixels() + 'px'"
                                x-on:click="addBlockAt(activeDay, $event)"
                            >
                                <template x-for="block in blocksFor(activeDay)" :key="block._key">
                                    <button
                                        type="button"
                                        x-on:click.stop="editBlock(block)"
                                        class="absolute left-2 right-2 overflow-hidden rounded-xl border px-3 py-2 text-left shadow-sm transition hover:shadow-md"
                                        :class="blockClasses(block)"
                                        :style="blockStyle(block)"
                                    >
                                        <div class="truncate text-sm font-semibold" x-text="block.label"></div>
                                        <div class="mt-0.5 text-[11px] opacity-75"><span x-text="block.starts_at"></span>–<span x-text="block.ends_at"></span></div>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="hidden overflow-x-auto rounded-2xl border border-gray-200 bg-white shadow-sm dark:border-white/10 dark:bg-gray-900 lg:block">
                    <div class="min-w-[1050px]">
                        <div class="grid grid-cols-[72px_repeat(5,minmax(0,1fr))] border-b border-gray-200 bg-gray-50 dark:border-white/10 dark:bg-white/5">
                            <div class="border-r border-gray-200 dark:border-white/10"></div>
                            <template x-for="day in days" :key="day.value">
                                <div class="border-r border-gray-200 px-3 py-3 text-center last:border-r-0 dark:border-white/10">
                                    <div class="font-semibold text-gray-950 dark:text-white" x-text="day.label"></div>
                                    <button
                                        type="button"
                                        class="mt-1 text-xs font-semibold text-primary-600 hover:underline dark:text-primary-400"
                                        x-on:click="addBlock(day.value)"
                                    >+ Agregar</button>
                                </div>
                            </template>
                        </div>

                        <div class="relative grid grid-cols-[72px_repeat(5,minmax(0,1fr))]" :style="'height:' + gridHeight() + 'px'">
                            <div class="relative border-r border-gray-200 bg-gray-50/60 dark:border-white/10 dark:bg-white/[0.03]">
                                <template x-for="mark in timeMarks()" :key="mark.minutes">
                                    <span
                                        class="absolute right-2 -translate-y-1/2 text-[11px] font-medium text-gray-500 dark:text-gray-400"
                                        :style="'top:' + mark.top + 'px'"
                                        x-text="mark.label"
                                    ></span>
                                </template>
                            </div>

                            <template x-for="day in days" :key="day.value">
                                <div
                                    class="schedule-canvas relative cursor-crosshair border-r border-gray-200 last:border-r-0 dark:border-white/10"
                                    :style="'--half-hour:' + halfHourPixels() + 'px'"
                                    x-on:click="addBlockAt(day.value, $event)"
                                >
                                    <template x-for="block in blocksFor(day.value)" :key="block._key">
                                        <button
                                            type="button"
                                            x-on:click.stop="editBlock(block)"
                                            class="absolute left-2 right-2 overflow-hidden rounded-xl border px-3 py-2 text-left shadow-sm transition hover:-translate-y-px hover:shadow-md"
                                            :class="blockClasses(block)"
                                            :style="blockStyle(block)"
                                        >
                                            <div class="truncate text-sm font-semibold" x-text="block.label"></div>
                                            <div class="mt-0.5 text-[11px] opacity-75">
                                                <span x-text="block.starts_at"></span>–<span x-text="block.ends_at"></span>
                                                <span x-show="block.responsibility === 'specialist'"> · Otro docente</span>
                                            </div>
                                        </button>
                                    </template>
                                </div>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="rounded-xl bg-gray-50 px-4 py-3 text-xs text-gray-600 dark:bg-white/5 dark:text-gray-400">
                    Al confirmar una planeación, se congela esta versión del horario. Los bloques marcados como “No incluir en mi planeación” ocupan su espacio real, pero la IA no genera actividades para ellos.
                </div>
            </div>
        </template>

        <div
            x-show="editing"
            x-cloak
            class="fixed inset-0 z-50"
            aria-modal="true"
            role="dialog"
        >
            <button type="button" class="absolute inset-0 bg-gray-950/50 backdrop-blur-[1px]" x-on:click="closeEditor()"></button>

            <aside class="absolute inset-y-0 right-0 flex w-full max-w-lg flex-col bg-white shadow-2xl dark:bg-gray-900">
                <div class="flex items-start justify-between border-b border-gray-200 px-5 py-4 dark:border-white/10">
                    <div>
                        <div class="text-xs font-semibold uppercase tracking-wide text-primary-600 dark:text-primary-400">
                            <span x-text="dayName(editing?.day_of_week)"></span>
                            · <span x-text="editing?.starts_at"></span>–<span x-text="editing?.ends_at"></span>
                        </div>
                        <h3 class="mt-1 text-xl font-semibold text-gray-950 dark:text-white">¿Qué ocurre en este horario?</h3>
                    </div>
                    <button type="button" class="rounded-lg p-2 text-gray-400 hover:bg-gray-100 hover:text-gray-700 dark:hover:bg-white/10 dark:hover:text-white" x-on:click="closeEditor()">✕</button>
                </div>

                <div class="flex-1 space-y-6 overflow-y-auto p-5">
                    <div>
                        <label class="mb-2 block text-sm font-semibold text-gray-800 dark:text-gray-200">Nombre que usa tu escuela</label>
                        <input
                            x-model="editing.label"
                            x-on:input="dirty = true"
                            type="text"
                            maxlength="120"
                            placeholder="Ej. Matemáticas, English, Robótica..."
                            class="block w-full rounded-xl border border-gray-300 bg-white px-4 py-3 text-lg font-semibold text-gray-950 shadow-sm outline-none focus:border-primary-500 focus:ring-2 focus:ring-primary-500/20 dark:border-white/15 dark:bg-gray-950 dark:text-white"
                        />

                        <div class="mt-3 flex flex-wrap gap-2">
                            <template x-for="field in fieldOptions" :key="field.code">
                                <button
                                    type="button"
                                    class="rounded-full bg-primary-50 px-3 py-1.5 text-xs font-semibold text-primary-700 hover:bg-primary-100 dark:bg-primary-500/10 dark:text-primary-300"
                                    x-on:click="useField(field)"
                                    x-text="field.name"
                                ></button>
                            </template>
                            <button type="button" class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300" x-on:click="usePreset('English')">English</button>
                            <button type="button" class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300" x-on:click="usePreset('Educación Física')">Educación Física</button>
                            <button type="button" class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300" x-on:click="usePreset('Computación')">Computación</button>
                            <button type="button" class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300" x-on:click="usePreset('Recreo')">Recreo</button>
                            <button type="button" class="rounded-full bg-gray-100 px-3 py-1.5 text-xs font-semibold text-gray-700 dark:bg-white/10 dark:text-gray-300" x-on:click="usePreset('Flexible')">Flexible</button>
                        </div>
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <label>
                            <span class="mb-1.5 block text-sm font-semibold text-gray-800 dark:text-gray-200">Empieza</span>
                            <input x-model="editing.starts_at" x-on:change="dirty = true" type="time" class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-gray-950 dark:border-white/15 dark:bg-gray-950 dark:text-white" />
                        </label>
                        <label>
                            <span class="mb-1.5 block text-sm font-semibold text-gray-800 dark:text-gray-200">Termina</span>
                            <input x-model="editing.ends_at" x-on:change="dirty = true" type="time" class="block w-full rounded-xl border border-gray-300 bg-white px-3 py-2.5 text-gray-950 dark:border-white/15 dark:bg-gray-950 dark:text-white" />
                        </label>
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-200">¿Quién la imparte?</div>
                        <div class="grid grid-cols-3 gap-2">
                            <button type="button" x-on:click="setResponsibility('main_teacher')" class="rounded-xl border px-3 py-2.5 text-sm font-semibold" :class="editing.responsibility === 'main_teacher' ? selectedButtonClass() : normalButtonClass()">Yo</button>
                            <button type="button" x-on:click="setResponsibility('specialist')" class="rounded-xl border px-3 py-2.5 text-sm font-semibold" :class="editing.responsibility === 'specialist' ? selectedButtonClass() : normalButtonClass()">Otro profesor</button>
                            <button type="button" x-on:click="setResponsibility('shared')" class="rounded-xl border px-3 py-2.5 text-sm font-semibold" :class="editing.responsibility === 'shared' ? selectedButtonClass() : normalButtonClass()">Compartida</button>
                        </div>
                    </div>

                    <label class="flex cursor-pointer items-start justify-between gap-4 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <span>
                            <span class="block text-sm font-semibold text-gray-900 dark:text-white">Incluir en mis planeaciones</span>
                            <span class="mt-1 block text-xs text-gray-500 dark:text-gray-400">Si lo desactivas, este bloque seguirá ocupando tiempo pero la IA no generará una actividad para él.</span>
                        </span>
                        <input x-model="editing.include_in_planning" x-on:change="dirty = true" type="checkbox" class="mt-1 rounded border-gray-300" />
                    </label>

                    <div x-show="fieldOptions.length > 0">
                        <div class="mb-1 text-sm font-semibold text-gray-800 dark:text-gray-200">Relación curricular <span class="font-normal text-gray-400">(opcional)</span></div>
                        <p class="mb-3 text-xs text-gray-500 dark:text-gray-400">Úsala sólo si quieres reservar este bloque para uno o más campos. Puedes dejarla vacía.</p>
                        <div class="space-y-2">
                            <template x-for="field in fieldOptions" :key="field.code">
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 px-3 py-2.5 dark:border-white/10">
                                    <input type="checkbox" :checked="hasField(field.code)" x-on:change="toggleField(field.code)" class="rounded border-gray-300" />
                                    <span class="text-sm text-gray-800 dark:text-gray-200" x-text="field.name"></span>
                                </label>
                            </template>
                        </div>
                    </div>

                    <div>
                        <button type="button" class="text-sm font-semibold text-primary-600 hover:underline dark:text-primary-400" x-on:click="showAdvanced = !showAdvanced">
                            <span x-text="showAdvanced ? 'Ocultar opciones' : 'Más opciones'"></span>
                        </button>
                        <div x-show="showAdvanced" x-cloak class="mt-3 space-y-3 rounded-xl bg-gray-50 p-4 dark:bg-white/5">
                            <label class="block">
                                <span class="mb-1.5 block text-xs font-semibold text-gray-600 dark:text-gray-400">Tipo de bloque</span>
                                <select x-model="editing.block_type" x-on:change="dirty = true" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-950 dark:border-white/15 dark:bg-gray-950 dark:text-white">
                                    <option value="class">Clase / espacio académico</option>
                                    <option value="flexible">Flexible</option>
                                    <option value="specialist">Clase con especialista</option>
                                    <option value="activity">Actividad / taller</option>
                                    <option value="break">Recreo / descanso</option>
                                    <option value="unavailable">No disponible</option>
                                </select>
                            </label>
                            <label class="flex items-center gap-3">
                                <input x-model="editing.is_flexible" x-on:change="dirty = true" type="checkbox" class="rounded border-gray-300" />
                                <span class="text-sm text-gray-700 dark:text-gray-300">La IA puede decidir qué contenido colocar aquí</span>
                            </label>
                            <label class="block">
                                <span class="mb-1.5 block text-xs font-semibold text-gray-600 dark:text-gray-400">Nota opcional</span>
                                <textarea x-model="editing.notes" x-on:input="dirty = true" rows="2" class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-gray-950 dark:border-white/15 dark:bg-gray-950 dark:text-white" placeholder="Ej. La imparte la maestra de computación"></textarea>
                            </label>
                        </div>
                    </div>

                    <div>
                        <div class="mb-2 text-sm font-semibold text-gray-800 dark:text-gray-200">Copiar este bloque a otro día</div>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="day in days" :key="day.value">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-200 px-3 py-2 text-xs font-semibold text-gray-700 hover:border-primary-300 hover:text-primary-700 disabled:opacity-30 dark:border-white/10 dark:text-gray-300"
                                    :disabled="Number(editing.day_of_week) === Number(day.value)"
                                    x-on:click="copyEditingTo(day.value)"
                                    x-text="day.short"
                                ></button>
                            </template>
                        </div>
                    </div>
                </div>

                <div class="flex items-center justify-between gap-3 border-t border-gray-200 p-4 dark:border-white/10">
                    <x-filament::button color="danger" type="button" x-on:click="removeEditing()">Eliminar</x-filament::button>
                    <x-filament::button type="button" x-on:click="closeEditor()">Listo</x-filament::button>
                </div>
            </aside>
        </div>
    </div>

    <script>
        function scheduleEditor(config) {
            return {
                days: [
                    { value: 1, label: 'Lunes', short: 'Lun' },
                    { value: 2, label: 'Martes', short: 'Mar' },
                    { value: 3, label: 'Miércoles', short: 'Mié' },
                    { value: 4, label: 'Jueves', short: 'Jue' },
                    { value: 5, label: 'Viernes', short: 'Vie' },
                ],
                fieldOptions: config.fieldOptions || [],
                activeDay: 1,
                dayStart: config.dayStart || '08:00',
                dayEnd: config.dayEnd || '12:30',
                defaultDuration: Number(config.defaultDuration || 50),
                blocks: (config.initialBlocks || []).map((b, i) => ({ ...b, _key: 'saved-' + i + '-' + Date.now() })),
                started: Boolean(config.hasSchedule),
                firstSetup: !config.hasSchedule,
                setupOpen: !config.hasSchedule,
                setupError: '',
                recessEnabled: false,
                recessStart: '10:00',
                recessEnd: '10:30',
                editing: null,
                showAdvanced: false,
                dirty: false,
                pxPerMinute: 1.45,

                applySetup() {
                    this.setupError = '';
                    const start = this.toMinutes(this.dayStart);
                    const end = this.toMinutes(this.dayEnd);
                    if (end <= start) {
                        this.setupError = 'La hora de salida debe ser posterior a la entrada.';
                        return;
                    }

                    if (this.firstSetup && this.recessEnabled) {
                        const rs = this.toMinutes(this.recessStart);
                        const re = this.toMinutes(this.recessEnd);
                        if (re <= rs || rs < start || re > end) {
                            this.setupError = 'El recreo debe quedar dentro de la jornada.';
                            return;
                        }
                        for (const day of this.days) {
                            this.blocks.push(this.newBlock(day.value, this.recessStart, this.recessEnd, {
                                label: 'Recreo',
                                block_type: 'break',
                                responsibility: 'external',
                                include_in_planning: false,
                                is_flexible: false,
                            }));
                        }
                    }

                    this.started = true;
                    this.firstSetup = false;
                    this.setupOpen = false;
                    this.dirty = true;
                    this.normalize();
                },

                blocksFor(day) {
                    return this.blocks
                        .filter(b => Number(b.day_of_week) === Number(day))
                        .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)));
                },

                dayName(day) {
                    return this.days.find(d => Number(d.value) === Number(day))?.label || '';
                },

                planableCount() {
                    return this.blocks.filter(b => Boolean(b.include_in_planning)).length;
                },

                gridHeight() {
                    return Math.max(420, (this.toMinutes(this.dayEnd) - this.toMinutes(this.dayStart)) * this.pxPerMinute);
                },

                halfHourPixels() {
                    return 30 * this.pxPerMinute;
                },

                timeMarks() {
                    const start = this.toMinutes(this.dayStart);
                    const end = this.toMinutes(this.dayEnd);
                    const marks = [];
                    for (let m = start; m <= end; m += 30) {
                        marks.push({
                            minutes: m,
                            label: this.fromMinutes(m),
                            top: (m - start) * this.pxPerMinute,
                        });
                    }
                    return marks;
                },

                blockStyle(block) {
                    const start = this.toMinutes(this.dayStart);
                    const top = Math.max(0, (this.toMinutes(block.starts_at) - start) * this.pxPerMinute);
                    const height = Math.max(28, this.duration(block.starts_at, block.ends_at) * this.pxPerMinute);
                    return 'top:' + top + 'px;height:' + height + 'px';
                },

                blockClasses(block) {
                    if (block.block_type === 'break') {
                        return 'border-dashed border-gray-300 bg-gray-100 text-gray-700 dark:border-white/15 dark:bg-white/10 dark:text-gray-200';
                    }
                    if (!block.include_in_planning) {
                        return 'border-gray-300 bg-gray-50 text-gray-600 dark:border-white/15 dark:bg-white/5 dark:text-gray-300';
                    }
                    if (block.is_flexible || block.block_type === 'flexible') {
                        return 'border-primary-200 bg-primary-50 text-primary-900 dark:border-primary-500/30 dark:bg-primary-500/10 dark:text-primary-100';
                    }
                    return 'border-blue-200 bg-blue-50 text-blue-950 dark:border-blue-500/30 dark:bg-blue-500/10 dark:text-blue-100';
                },

                addBlock(day) {
                    const existing = this.blocksFor(day);
                    const start = existing.length ? existing[existing.length - 1].ends_at : this.dayStart;
                    this.openNewBlock(day, start);
                },

                addBlockAt(day, event) {
                    const rect = event.currentTarget.getBoundingClientRect();
                    const fraction = Math.max(0, Math.min(1, (event.clientY - rect.top) / rect.height));
                    const startMinutes = this.toMinutes(this.dayStart);
                    const endMinutes = this.toMinutes(this.dayEnd);
                    let target = startMinutes + ((endMinutes - startMinutes) * fraction);
                    target = Math.round(target / 5) * 5;
                    target = Math.max(startMinutes, Math.min(endMinutes - 15, target));
                    this.openNewBlock(day, this.fromMinutes(target));
                },

                openNewBlock(day, start) {
                    let end = this.addMinutes(start, this.defaultDuration);
                    if (this.toMinutes(end) > this.toMinutes(this.dayEnd)) {
                        end = this.dayEnd;
                    }
                    const block = this.newBlock(day, start, end);
                    this.blocks.push(block);
                    this.editBlock(block);
                    this.dirty = true;
                    this.normalize();
                },

                newBlock(day, start, end, overrides = {}) {
                    return {
                        _key: 'block-' + Date.now() + '-' + Math.random().toString(16).slice(2),
                        day_of_week: Number(day),
                        sequence: this.blocksFor(day).length + 1,
                        starts_at: start,
                        ends_at: end,
                        label: 'Nueva clase',
                        block_type: 'class',
                        responsibility: 'main_teacher',
                        include_in_planning: true,
                        is_flexible: false,
                        field_codes: [],
                        notes: null,
                        ...overrides,
                    };
                },

                editBlock(block) {
                    this.editing = block;
                    this.showAdvanced = false;
                },

                closeEditor() {
                    if (this.editing && !String(this.editing.label || '').trim()) {
                        this.editing.label = 'Nueva clase';
                    }
                    this.editing = null;
                    this.showAdvanced = false;
                    this.normalize();
                },

                useField(field) {
                    if (!this.editing) return;
                    this.editing.label = field.name;
                    this.editing.field_codes = [field.code];
                    this.editing.block_type = 'class';
                    this.editing.include_in_planning = true;
                    this.dirty = true;
                },

                usePreset(label) {
                    if (!this.editing) return;
                    this.editing.label = label;
                    this.editing.field_codes = [];

                    if (label === 'Recreo') {
                        this.editing.block_type = 'break';
                        this.editing.responsibility = 'external';
                        this.editing.include_in_planning = false;
                        this.editing.is_flexible = false;
                    } else if (label === 'Flexible') {
                        this.editing.block_type = 'flexible';
                        this.editing.responsibility = 'main_teacher';
                        this.editing.include_in_planning = true;
                        this.editing.is_flexible = true;
                    } else {
                        this.editing.block_type = 'class';
                    }

                    this.dirty = true;
                },

                setResponsibility(value) {
                    if (!this.editing) return;
                    this.editing.responsibility = value;
                    this.dirty = true;
                },

                selectedButtonClass() {
                    return 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-500/10 dark:text-primary-300';
                },

                normalButtonClass() {
                    return 'border-gray-200 bg-white text-gray-600 dark:border-white/10 dark:bg-gray-950 dark:text-gray-300';
                },

                hasField(code) {
                    return Array.isArray(this.editing?.field_codes) && this.editing.field_codes.includes(code);
                },

                toggleField(code) {
                    if (!this.editing) return;
                    const fields = Array.isArray(this.editing.field_codes) ? [...this.editing.field_codes] : [];
                    const index = fields.indexOf(code);
                    if (index >= 0) fields.splice(index, 1);
                    else fields.push(code);
                    this.editing.field_codes = fields;
                    this.dirty = true;
                },

                copyEditingTo(day) {
                    if (!this.editing || Number(day) === Number(this.editing.day_of_week)) return;
                    const copy = JSON.parse(JSON.stringify(this.editing));
                    copy._key = 'copy-' + Date.now() + '-' + Math.random().toString(16).slice(2);
                    copy.day_of_week = Number(day);
                    copy.sequence = this.blocksFor(day).length + 1;
                    this.blocks.push(copy);
                    this.dirty = true;
                    this.normalize();
                },

                removeEditing() {
                    if (!this.editing) return;
                    const key = this.editing._key;
                    this.blocks = this.blocks.filter(b => b._key !== key);
                    this.editing = null;
                    this.showAdvanced = false;
                    this.dirty = true;
                    this.normalize();
                },

                normalize() {
                    for (const day of this.days) {
                        this.blocksFor(day.value).forEach((block, index) => block.sequence = index + 1);
                    }
                },

                duration(start, end) {
                    return Math.max(1, this.toMinutes(end) - this.toMinutes(start));
                },

                toMinutes(time) {
                    const [h, m] = String(time || '00:00').split(':').map(Number);
                    return (h * 60) + m;
                },

                fromMinutes(total) {
                    total = Math.max(0, Math.min(23 * 60 + 59, Math.round(total)));
                    return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
                },

                addMinutes(time, minutes) {
                    return this.fromMinutes(this.toMinutes(time) + Number(minutes || 0));
                },

                save(wire) {
                    this.normalize();
                    const clean = this.blocks.map(({ _key, ...block }) => block);
                    wire.set('dayStartsAt', this.dayStart);
                    wire.set('dayEndsAt', this.dayEnd);
                    wire.set('blocks', clean).then(() => wire.saveSchedule()).then(() => {
                        this.dirty = false;
                        this.firstSetup = false;
                    });
                },
            };
        }
    </script>
</x-filament-panels::page>
