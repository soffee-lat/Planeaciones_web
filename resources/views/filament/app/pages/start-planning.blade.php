<x-filament-panels::page>
    <style>
        .planning-start {
            max-width: 980px;
            margin: 0 auto;
        }
        .planning-start__intro {
            margin-bottom: 1.5rem;
        }
        .planning-start__lead {
            margin: .35rem 0 0;
            max-width: 680px;
            color: rgb(107 114 128);
            font-size: .95rem;
            line-height: 1.55;
        }
        .dark .planning-start__lead {
            color: rgb(156 163 175);
        }
        .planning-start__steps {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: .5rem;
            margin: 1.25rem 0 1.5rem;
        }
        .planning-start__step {
            display: flex;
            align-items: center;
            gap: .55rem;
            min-width: 0;
            padding: .7rem .8rem;
            border-radius: .8rem;
            background: rgb(249 250 251);
            color: rgb(107 114 128);
            font-size: .82rem;
            font-weight: 600;
        }
        .dark .planning-start__step {
            background: rgb(24 24 27);
            color: rgb(161 161 170);
        }
        .planning-start__step.is-active {
            background: color-mix(in srgb, var(--primary-500) 12%, transparent);
            color: rgb(var(--primary-600));
        }
        .dark .planning-start__step.is-active {
            color: rgb(var(--primary-400));
        }
        .planning-start__step-number {
            display: grid;
            place-items: center;
            width: 1.65rem;
            height: 1.65rem;
            flex: 0 0 auto;
            border-radius: 999px;
            background: rgb(229 231 235);
            font-size: .75rem;
            font-weight: 700;
        }
        .dark .planning-start__step-number {
            background: rgb(63 63 70);
        }
        .planning-start__step.is-active .planning-start__step-number {
            background: rgb(var(--primary-600));
            color: white;
        }
        .planning-start__card {
            overflow: hidden;
            border: 1px solid rgb(229 231 235);
            border-radius: 1rem;
            background: white;
            box-shadow: 0 1px 2px rgb(0 0 0 / .04);
        }
        .dark .planning-start__card {
            border-color: rgb(63 63 70);
            background: rgb(24 24 27);
        }
        .planning-start__body {
            padding: 1.5rem;
        }
        .planning-start__grid {
            display: grid;
            grid-template-columns: repeat(2, minmax(0, 1fr));
            gap: 1.15rem;
        }
        .planning-start__field--full {
            grid-column: 1 / -1;
        }
        .planning-start__label {
            display: block;
            margin-bottom: .45rem;
            color: rgb(31 41 55);
            font-size: .875rem;
            font-weight: 650;
        }
        .dark .planning-start__label {
            color: rgb(244 244 245);
        }
        .planning-start__optional {
            color: rgb(107 114 128);
            font-weight: 400;
        }
        .planning-start__control {
            width: 100%;
            min-height: 2.75rem;
            padding: .65rem .8rem;
            border: 1px solid rgb(209 213 219);
            border-radius: .75rem;
            background: white;
            color: rgb(17 24 39);
            font-size: .9rem;
            line-height: 1.35;
            outline: none;
            transition: border-color .15s ease, box-shadow .15s ease, background-color .15s ease;
        }
        textarea.planning-start__control {
            min-height: 7rem;
            resize: vertical;
        }
        .planning-start__control:focus {
            border-color: rgb(var(--primary-500));
            box-shadow: 0 0 0 3px color-mix(in srgb, rgb(var(--primary-500)) 16%, transparent);
        }
        .dark .planning-start__control {
            border-color: rgb(82 82 91);
            background: rgb(39 39 42);
            color: rgb(250 250 250);
        }
        .planning-start__help {
            margin-top: .4rem;
            color: rgb(107 114 128);
            font-size: .78rem;
            line-height: 1.45;
        }
        .dark .planning-start__help {
            color: rgb(161 161 170);
        }
        .planning-start__error {
            margin-top: .4rem;
            color: rgb(220 38 38);
            font-size: .8rem;
        }
        .planning-start__group-summary {
            grid-column: 1 / -1;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: .45rem .65rem;
            padding: .75rem .9rem;
            border-radius: .75rem;
            background: rgb(249 250 251);
            color: rgb(75 85 99);
            font-size: .82rem;
        }
        .dark .planning-start__group-summary {
            background: rgb(39 39 42);
            color: rgb(212 212 216);
        }
        .planning-start__dot {
            color: rgb(156 163 175);
        }
        .planning-start__footer {
            display: flex;
            align-items: center;
            justify-content: space-between;
            gap: 1rem;
            padding: 1rem 1.5rem;
            border-top: 1px solid rgb(229 231 235);
            background: rgb(249 250 251 / .8);
        }
        .dark .planning-start__footer {
            border-color: rgb(63 63 70);
            background: rgb(24 24 27);
        }
        .planning-start__footer-note {
            color: rgb(107 114 128);
            font-size: .8rem;
            line-height: 1.4;
        }
        .dark .planning-start__footer-note {
            color: rgb(161 161 170);
        }
        @media (max-width: 700px) {
            .planning-start__steps {
                grid-template-columns: 1fr;
            }
            .planning-start__step:not(.is-active) {
                display: none;
            }
            .planning-start__grid {
                grid-template-columns: 1fr;
            }
            .planning-start__field--full,
            .planning-start__group-summary {
                grid-column: auto;
            }
            .planning-start__body {
                padding: 1.1rem;
            }
            .planning-start__footer {
                align-items: stretch;
                flex-direction: column;
                padding: 1rem 1.1rem;
            }
            .planning-start__footer .fi-btn {
                width: 100%;
                justify-content: center;
            }
        }
    </style>

    <div class="planning-start">
        <div class="planning-start__intro">
            <h2 class="text-xl font-semibold tracking-tight text-gray-950 dark:text-white">Prepara tu planeación</h2>
            <p class="planning-start__lead">Completa sólo lo esencial. Usaremos automáticamente el grado, currículo y perfil pedagógico que ya configuraste para tu grupo.</p>
        </div>

        <div class="planning-start__steps" aria-label="Progreso de la planeación">
            <div class="planning-start__step is-active">
                <span class="planning-start__step-number">1</span>
                <span>Datos de la planeación</span>
            </div>
            <div class="planning-start__step">
                <span class="planning-start__step-number">2</span>
                <span>Conexiones curriculares</span>
            </div>
            <div class="planning-start__step">
                <span class="planning-start__step-number">3</span>
                <span>Generar y revisar</span>
            </div>
        </div>

        <form wire:submit="start" class="planning-start__card">
            <div class="planning-start__body">
                <div class="planning-start__grid">
                    <div>
                        <label for="planning-group" class="planning-start__label">Grupo</label>
                        <select id="planning-group" wire:model.live="group_id" class="planning-start__control">
                            <option value="">Selecciona un grupo…</option>
                            @foreach ($groups as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('group_id') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="planning-format" class="planning-start__label">Formato de salida</label>
                        <select id="planning-format" wire:model="format_version_id" class="planning-start__control">
                            <option value="">Selecciona cómo quieres recibirla…</option>
                            @foreach ($formats as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="planning-start__help">El formato institucional conserva la plantilla que configuraste. El estándar usa una presentación genérica.</p>
                        @error('format_version_id') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    @php($selectedGroup = $this->selectedGroup())
                    @if ($selectedGroup)
                        <div class="planning-start__group-summary">
                            <strong>{{ $selectedGroup->name }}</strong>
                            <span class="planning-start__dot">•</span>
                            <span>{{ $selectedGroup->grade?->name ?? 'Grado configurado' }}</span>
                            @if ($selectedGroup->profile?->student_count)
                                <span class="planning-start__dot">•</span>
                                <span>{{ $selectedGroup->profile->student_count }} estudiantes</span>
                            @endif
                            @if ($selectedGroup->profile?->session_minutes)
                                <span class="planning-start__dot">•</span>
                                <span>Sesiones de {{ $selectedGroup->profile->session_minutes }} min</span>
                            @endif
                        </div>
                    @endif

                    <div>
                        <label for="planning-start-date" class="planning-start__label">Fecha inicial</label>
                        <input id="planning-start-date" type="date" wire:model="starts_on" class="planning-start__control">
                        @error('starts_on') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label for="planning-end-date" class="planning-start__label">Fecha final</label>
                        <input id="planning-end-date" type="date" wire:model="ends_on" class="planning-start__control">
                        @error('ends_on') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="planning-start__field--full">
                        <label for="planning-focus" class="planning-start__label">¿Qué quieres trabajar?</label>
                        <textarea
                            id="planning-focus"
                            wire:model="work_focus"
                            maxlength="255"
                            rows="3"
                            placeholder="Ej. La Independencia de México, conocer mi cuerpo, multiplicación por 2…"
                            class="planning-start__control"
                        ></textarea>
                        <p class="planning-start__help">Escribe el tema, proyecto o necesidad principal. No necesitas redactar la planeación completa.</p>
                        @error('work_focus') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="planning-start__field--full">
                        <label for="planning-context" class="planning-start__label">Indicaciones adicionales <span class="planning-start__optional">· opcional</span></label>
                        <textarea
                            id="planning-context"
                            wire:model="context_note"
                            rows="4"
                            placeholder="Ej. El lunes habrá una actividad especial; quiero integrar números y lenguaje; evitar tarea para casa…"
                            class="planning-start__control"
                        ></textarea>
                        @error('context_note') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="planning-start__footer">
                <p class="planning-start__footer-note">En el siguiente paso podrás revisar y ajustar contenidos, PDA y ejes antes de generar.</p>
                <x-filament::button type="submit" size="lg" icon="heroicon-o-arrow-right" icon-position="after" wire:loading.attr="disabled">
                    <span wire:loading.remove>Continuar con el currículo</span>
                    <span wire:loading>Preparando…</span>
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
