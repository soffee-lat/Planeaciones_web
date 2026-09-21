<x-filament-panels::page>
    <style>
        .planning-native-select {
            color: #111827 !important;
            background-color: #ffffff !important;
        }

        .planning-native-select option {
            color: #111827 !important;
            background-color: #ffffff !important;
        }

        html.dark .planning-native-select,
        .dark .planning-native-select {
            color: #f8fafc !important;
            background-color: #111827 !important;
            border-color: #374151 !important;
        }

        html.dark .planning-native-select option,
        .dark .planning-native-select option {
            color: #f8fafc !important;
            background-color: #111827 !important;
        }

        .planning-native-select:disabled {
            color: #6b7280 !important;
        }

        html.dark .planning-native-select:disabled,
        .dark .planning-native-select:disabled {
            color: #94a3b8 !important;
        }
    </style>
    @php($periodOptions = $this->periodOptions())
    @php($subjects = $this->subjectOptions())
    @php($selectedGroup = $this->selectedGroup())

    <div class="mx-auto grid w-full max-w-6xl gap-6 xl:grid-cols-[minmax(0,1fr)_340px]">
        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">¿Qué periodo vas a planear?</x-slot>
                <x-slot name="description">Elige el grupo y después selecciona una semana o un mes ya delimitado. Las fechas se calculan automáticamente.</x-slot>

                <form wire:submit="start" class="space-y-6">
                    <div>
                        <label class="mb-2 block text-sm font-medium">Grupo</label>
                        <select
                            wire:model.live="group_id"
                            @disabled($draft_id)
                            class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <option value="">Selecciona un grupo…</option>
                            @foreach ($groups as $id => $label)
                                <option value="{{ $id }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('group_id') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">Tipo de planeación</label>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                <input type="radio" value="week" wire:model.live="period_type" class="mt-1">
                                <span>
                                    <strong class="block text-sm">Por semana</strong>
                                    <span class="mt-1 block text-xs text-gray-500">Seleccionas una semana escolar ya delimitada de lunes a viernes.</span>
                                </span>
                            </label>
                            <label class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-4 dark:border-white/10">
                                <input type="radio" value="month" wire:model.live="period_type" class="mt-1">
                                <span>
                                    <strong class="block text-sm">Por mes</strong>
                                    <span class="mt-1 block text-xs text-gray-500">El mes se divide automáticamente en semanas completas o parciales.</span>
                                </span>
                            </label>
                        </div>
                    </div>

                    <div>
                        <label class="mb-2 block text-sm font-medium">
                            {{ $period_type === 'month' ? 'Mes a planear' : 'Semana a planear' }}
                        </label>
                        <select
                            wire:model.live="period_key"
                            @disabled(!$group_id)
                            class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm disabled:opacity-60 dark:border-gray-700 dark:bg-gray-900"
                        >
                            <option value="">
                                {{ $group_id ? 'Selecciona un periodo…' : 'Primero selecciona un grupo' }}
                            </option>
                            @foreach ($periodOptions as $key => $label)
                                <option value="{{ $key }}">{{ $label }}</option>
                            @endforeach
                        </select>
                        @error('period_key') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        @if($group_id)
                            <p class="mt-2 text-xs text-gray-500">
                                Los periodos con ⚠ ya tienen otra planeación que toca esas fechas. Puedes ver el aviso antes de continuar.
                            </p>
                        @endif
                    </div>

                    @if ($period_type === 'month' && $period_key)
                        <div class="rounded-2xl border border-primary-200 bg-primary-50/60 p-5 dark:border-primary-500/20 dark:bg-primary-500/5">
                            <div class="mb-4">
                                <h3 class="text-sm font-semibold">Proyecto integrador del mes <span class="font-normal text-gray-500">(opcional)</span></h3>
                                <p class="mt-1 text-xs text-gray-600 dark:text-gray-300">
                                    Es un hilo común entre varias materias. No sustituye los temas propios de cada materia.
                                </p>
                            </div>

                            <div class="space-y-4">
                                <div>
                                    <label class="mb-2 block text-sm font-medium">Nombre del proyecto integrador</label>
                                    <input
                                        type="text"
                                        wire:model="integrative_project"
                                        maxlength="255"
                                        placeholder="Ej. Cuidemos nuestra comunidad"
                                        class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                                    >
                                    @error('integrative_project') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                                </div>

                                <div>
                                    <label class="mb-2 block text-sm font-medium">Propósito <span class="font-normal text-gray-500">(opcional)</span></label>
                                    <textarea
                                        wire:model="integrative_project_purpose"
                                        rows="3"
                                        placeholder="Describe brevemente qué conecta el proyecto y qué busca lograr."
                                        class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                                    ></textarea>
                                    @error('integrative_project_purpose') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                                </div>
                            </div>
                        </div>
                    @endif

                    @if ($weeks !== [])
                        <div class="space-y-4">
                            <div>
                                <h3 class="text-base font-semibold">Temas por semana y materia</h3>
                                <p class="mt-1 text-sm text-gray-500">
                                    Asigna cada tema a su materia principal. Las materias sin tema propio podrán participar de forma transversal cuando sea pertinente.
                                </p>
                            </div>

                            @foreach ($weeks as $weekIndex => $week)
                                <section
                                    wire:key="planning-week-{{ $week['sequence'] }}-{{ $week['starts_on'] }}"
                                    class="rounded-2xl border border-gray-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-gray-900"
                                >
                                    <div class="mb-4 flex flex-wrap items-start justify-between gap-3">
                                        <div>
                                            <div class="text-xs font-semibold uppercase tracking-wide text-primary-600">
                                                Semana {{ $week['sequence'] }}
                                            </div>
                                            <h4 class="mt-1 text-base font-semibold">{{ $week['label'] }}</h4>
                                        </div>

                                        @if (!empty($week['occupied']))
                                            <span class="rounded-full bg-warning-50 px-3 py-1 text-xs font-semibold text-warning-700 dark:bg-warning-500/10 dark:text-warning-300">
                                                ⚠ Ya existe otra planeación en estas fechas
                                            </span>
                                        @endif
                                    </div>

                                    <div class="space-y-3">
                                        @foreach (($week['topics'] ?? []) as $topicIndex => $topic)
                                            <div
                                                wire:key="planning-topic-{{ $weekIndex }}-{{ $topicIndex }}"
                                                class="grid gap-3 rounded-xl border border-gray-100 bg-gray-50 p-4 md:grid-cols-[minmax(0,1.4fr)_minmax(190px,.8fr)_auto] dark:border-white/5 dark:bg-white/5"
                                            >
                                                <div>
                                                    <label class="mb-1.5 block text-xs font-semibold text-gray-600 dark:text-gray-300">Tema</label>
                                                    <input
                                                        type="text"
                                                        wire:model="weeks.{{ $weekIndex }}.topics.{{ $topicIndex }}.topic"
                                                        maxlength="255"
                                                        placeholder="Ej. Números hasta 10,000"
                                                        class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950"
                                                    >
                                                    @error("weeks.$weekIndex.topics.$topicIndex.topic")
                                                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                <div>
                                                    <label class="mb-1.5 block text-xs font-semibold text-gray-600 dark:text-gray-300">Materia principal</label>
                                                    <select
                                                        wire:model="weeks.{{ $weekIndex }}.topics.{{ $topicIndex }}.group_subject_id"
                                                        class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-950"
                                                    >
                                                        <option value="">Selecciona…</option>
                                                        @foreach ($subjects as $subjectId => $subjectName)
                                                            <option value="{{ $subjectId }}">{{ $subjectName }}</option>
                                                        @endforeach
                                                    </select>
                                                    @error("weeks.$weekIndex.topics.$topicIndex.group_subject_id")
                                                        <p class="mt-1 text-xs text-danger-600">{{ $message }}</p>
                                                    @enderror
                                                </div>

                                                <div class="flex items-end">
                                                    <button
                                                        type="button"
                                                        wire:click="removeTopic({{ $weekIndex }}, {{ $topicIndex }})"
                                                        class="inline-flex h-10 items-center justify-center rounded-lg border border-gray-200 px-3 text-sm text-gray-500 hover:bg-white hover:text-danger-600 dark:border-white/10 dark:hover:bg-gray-800"
                                                        title="Quitar tema"
                                                    >
                                                        Quitar
                                                    </button>
                                                </div>
                                            </div>
                                        @endforeach

                                        @error("weeks.$weekIndex.topics")
                                            <p class="text-sm text-danger-600">{{ $message }}</p>
                                        @enderror

                                        <button
                                            type="button"
                                            wire:click="addTopic({{ $weekIndex }})"
                                            class="inline-flex items-center gap-2 rounded-lg border border-dashed border-primary-300 px-3 py-2 text-sm font-semibold text-primary-700 hover:bg-primary-50 dark:border-primary-500/30 dark:text-primary-300 dark:hover:bg-primary-500/5"
                                        >
                                            <span>＋</span> Agregar otro tema
                                        </button>
                                    </div>
                                </section>
                            @endforeach
                        </div>
                    @endif

                    @if ($period_key)
                        <div>
                            <label class="mb-2 block text-sm font-medium">
                                Algo que debamos considerar <span class="font-normal text-gray-500">(opcional)</span>
                            </label>
                            <textarea
                                wire:model="context_note"
                                rows="4"
                                placeholder="Ej. Habrá una actividad especial el viernes, quiero reforzar lectura, considerar material del libro…"
                                class="planning-native-select block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                            ></textarea>
                            @error('context_note') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                        </div>

                        <div class="flex flex-wrap items-center gap-3 pt-2">
                            <x-filament::button type="submit" size="lg" icon="heroicon-o-arrow-right" wire:loading.attr="disabled">
                                {{ $draft_id ? 'Guardar cambios y revisar conexiones' : 'Ver conexiones curriculares' }}
                            </x-filament::button>
                            <span class="text-sm text-gray-500" wire:loading>Preparando la estructura…</span>
                        </div>
                    @endif
                </form>
            </x-filament::section>
        </div>

        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">Grupo</x-slot>
                @if ($selectedGroup)
                    <div class="space-y-2 text-sm">
                        <p><strong>{{ $selectedGroup->name }}</strong></p>
                        <p>Grado: {{ $selectedGroup->grade?->name ?? 'Configurado en tu grupo' }}</p>
                        <p>Ciclo escolar: {{ $selectedGroup->school_year }}</p>
                        @if ($selectedGroup->profile?->session_minutes)
                            <p>Sesión habitual: {{ $selectedGroup->profile->session_minutes }} min</p>
                        @endif
                    </div>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">
                        Al seleccionar un grupo reutilizaremos su currículo, perfil pedagógico, materias y horario.
                    </p>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Cómo se usará</x-slot>
                <div class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    <p><strong>1.</strong> El periodo define las semanas reales de la planeación.</p>
                    <p><strong>2.</strong> Cada tema queda ligado a una materia principal.</p>
                    <p><strong>3.</strong> El horario determina en qué bloques se desarrolla.</p>
                    <p><strong>4.</strong> Las materias sin tema asignado pueden reforzar transversalmente los temas activos.</p>
                    <p><strong>5.</strong> Si es mensual, el sistema conserva la progresión entre semanas.</p>
                </div>
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Después</x-slot>
                <ol class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    @if($draft_id)
                        <li><strong>Ahora.</strong> Guardaremos los cambios sobre esta misma planeación.</li>
                    @endif
                    <li><strong>1.</strong> Revisas contenidos, PDA, campos y ejes relacionados.</li>
                    <li><strong>2.</strong> Confirmas las conexiones curriculares.</li>
                    <li><strong>3.</strong> La IA genera respetando semanas, materias, horario y transversalidad.</li>
                </ol>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
