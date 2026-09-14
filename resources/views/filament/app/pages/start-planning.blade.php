<x-filament-panels::page>
    <div class="planning-start">
        <div class="planning-start__intro">
            <div class="pd-eyebrow">Nueva planeación</div>
            <h2 class="mt-1 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">Prepara tu planeación</h2>
            <p class="planning-start__lead">Completa sólo lo esencial. Primero generaremos el contenido pedagógico y al final podrás elegir cómo exportarlo.</p>
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

        @if (! $hasProductionCurriculum)
            <div class="pd-status-banner mb-4" style="border-color: rgba(245, 158, 11, .35); background: rgba(245, 158, 11, .08);">
                <span class="pd-icon-tile" style="color: rgb(245 158 11); background: rgba(245, 158, 11, .12);">
                    <x-filament::icon icon="heroicon-o-exclamation-triangle" class="h-5 w-5" />
                </span>
                <div>
                    <div class="font-bold text-gray-950 dark:text-white">Falta activar el currículo oficial</div>
                    <p class="mt-1 text-sm pd-muted">Las planeaciones reales ya no pueden generarse con contenidos, PDA o ejes de demostración. Un administrador debe publicar el catálogo SEP validado y después asignarlo al grupo.</p>
                </div>
            </div>
        @endif

        <form wire:submit="start" class="planning-start__card">
            <div class="planning-start__body">
                <div class="planning-start__grid">
                    <div class="planning-start__field--full">
                        <label for="planning-group" class="planning-start__label">Grupo</label>
                        <select id="planning-group" wire:model.live="group_id" class="planning-start__control" @disabled(! $hasProductionCurriculum)>
                            <option value="">Selecciona un grupo…</option>
                            @foreach ($groups as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        <p class="planning-start__help">Sólo aparecen grupos con perfil completo y un currículo publicado apto para planeaciones reales.</p>
                        @error('group_id') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    @php($selectedGroup = $this->selectedGroup())
                    @if ($selectedGroup)
                        <div class="planning-start__group-summary">
                            <span class="pd-icon-tile" style="width: 1.8rem; height: 1.8rem;">
                                <x-filament::icon icon="heroicon-o-user-group" class="h-4 w-4" />
                            </span>
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
                        <textarea id="planning-focus" wire:model="work_focus" maxlength="255" rows="3" placeholder="Ej. La Independencia de México, conocer mi cuerpo, multiplicación por 2…" class="planning-start__control"></textarea>
                        <p class="planning-start__help">Escribe el tema, proyecto o necesidad principal. No necesitas redactar la planeación completa.</p>
                        @error('work_focus') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>

                    <div class="planning-start__field--full">
                        <label for="planning-context" class="planning-start__label">Indicaciones adicionales <span class="planning-start__optional">· opcional</span></label>
                        <textarea id="planning-context" wire:model="context_note" rows="4" placeholder="Ej. El lunes habrá una actividad especial; quiero integrar números y lenguaje; evitar tarea para casa…" class="planning-start__control"></textarea>
                        @error('context_note') <p class="planning-start__error">{{ $message }}</p> @enderror
                    </div>
                </div>
            </div>

            <div class="planning-start__footer">
                <p class="planning-start__footer-note">En el siguiente paso revisarás contenidos, PDA y ejes. El formato se elegirá sólo cuando la planeación esté lista para exportarse.</p>
                <x-filament::button type="submit" size="lg" icon="heroicon-o-arrow-right" icon-position="after" wire:loading.attr="disabled" :disabled="! $hasProductionCurriculum">
                    <span wire:loading.remove>Continuar con el currículo</span>
                    <span wire:loading>Preparando…</span>
                </x-filament::button>
            </div>
        </form>
    </div>
</x-filament-panels::page>
