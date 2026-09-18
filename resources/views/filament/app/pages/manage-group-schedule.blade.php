<x-filament-panels::page>
    <style>[x-cloak]{display:none!important}</style>

    <script>
        window.groupScheduleEditor = function (initial, fieldOptions) {
            const normalizeTime = (value) => String(value || '').slice(0, 5);
            const toMinutes = (value) => {
                const parts = normalizeTime(value).split(':').map(Number);
                return ((parts[0] || 0) * 60) + (parts[1] || 0);
            };
            const fromMinutes = (minutes) => {
                const safe = Math.max(0, Math.min(1439, Number(minutes) || 0));
                return String(Math.floor(safe / 60)).padStart(2, '0') + ':' + String(safe % 60).padStart(2, '0');
            };

            return {
                days: [
                    { value: 1, label: 'Lunes', short: 'Lun' },
                    { value: 2, label: 'Martes', short: 'Mar' },
                    { value: 3, label: 'Miércoles', short: 'Mié' },
                    { value: 4, label: 'Jueves', short: 'Jue' },
                    { value: 5, label: 'Viernes', short: 'Vie' },
                    { value: 6, label: 'Sábado', short: 'Sáb' },
                    { value: 7, label: 'Domingo', short: 'Dom' },
                ],
                fieldOptions,
                name: initial.name || 'Horario habitual',
                revision: Number(initial.revision || 0),
                activeDays: Array.isArray(initial.active_days) && initial.active_days.length ? initial.active_days.map(Number) : [1,2,3,4,5],
                dayStart: normalizeTime(initial.day_starts_at || '08:00'),
                dayEnd: normalizeTime(initial.day_ends_at || '12:30'),
                blocks: (initial.blocks || []).map((b) => ({
                    day_of_week: Number(b.day_of_week),
                    sequence: Number(b.sequence || 1),
                    starts_at: normalizeTime(b.starts_at),
                    ends_at: normalizeTime(b.ends_at),
                    block_type: b.block_type || 'instructional',
                    label: b.label || '',
                    responsibility: b.responsibility || 'main_teacher',
                    include_in_planning: Boolean(b.include_in_planning),
                    field_codes: Array.isArray(b.field_codes) ? [...b.field_codes] : [],
                    notes: b.notes || '',
                })),
                selectedDay: Number((initial.active_days || [1])[0] || 1),
                modalOpen: false,
                editingIndex: null,
                repeatActive: false,
                localError: '',
                saving: false,
                savedMessage: '',
                breakStart: '10:30',
                breakEnd: '11:00',
                draft: {},

                isActive(day) {
                    return this.activeDays.includes(Number(day));
                },

                toggleDay(day) {
                    day = Number(day);
                    if (this.isActive(day)) {
                        if (this.activeDays.length === 1) return;
                        this.activeDays = this.activeDays.filter((d) => d !== day);
                        if (this.selectedDay === day) this.selectedDay = this.activeDays[0];
                    } else {
                        this.activeDays = [...this.activeDays, day].sort((a,b) => a-b);
                        this.selectedDay = day;
                    }
                    this.savedMessage = '';
                },

                explicitBlocks(day) {
                    return this.blocks
                        .map((block, index) => ({ ...block, _index: index }))
                        .filter((block) => Number(block.day_of_week) === Number(day))
                        .sort((a, b) => toMinutes(a.starts_at) - toMinutes(b.starts_at));
                },

                timelineForDay(day) {
                    if (!this.isActive(day)) return [];
                    const timeline = [];
                    const blocks = this.explicitBlocks(day);
                    let cursor = toMinutes(this.dayStart);
                    const end = toMinutes(this.dayEnd);

                    for (const block of blocks) {
                        const start = toMinutes(block.starts_at);
                        const blockEnd = toMinutes(block.ends_at);
                        if (start > cursor) {
                            timeline.push({
                                implicit: true,
                                starts_at: fromMinutes(cursor),
                                ends_at: fromMinutes(start),
                                label: 'Tiempo disponible',
                                duration: start - cursor,
                                day_of_week: Number(day),
                            });
                        }
                        timeline.push({
                            ...block,
                            implicit: false,
                            duration: Math.max(0, blockEnd - start),
                        });
                        cursor = Math.max(cursor, blockEnd);
                    }

                    if (cursor < end) {
                        timeline.push({
                            implicit: true,
                            starts_at: fromMinutes(cursor),
                            ends_at: fromMinutes(end),
                            label: 'Tiempo disponible',
                            duration: end - cursor,
                            day_of_week: Number(day),
                        });
                    }

                    return timeline;
                },

                availableMinutes(day) {
                    return this.timelineForDay(day)
                        .filter((item) => item.implicit || (item.include_in_planning && !['break','external'].includes(item.block_type) && item.responsibility !== 'external'))
                        .reduce((sum, item) => sum + Number(item.duration || 0), 0);
                },

                totalWeeklyMinutes() {
                    return this.activeDays.reduce((sum, day) => sum + this.availableMinutes(day), 0);
                },

                blockTone(item) {
                    if (item.implicit) return 'border-dashed border-gray-300 bg-gray-50 dark:border-gray-700 dark:bg-gray-900/40';
                    if (item.block_type === 'break') return 'border-amber-300 bg-amber-50 dark:border-amber-800 dark:bg-amber-950/30';
                    if (!item.include_in_planning || item.block_type === 'external' || item.responsibility === 'external') return 'border-gray-300 bg-gray-100 dark:border-gray-700 dark:bg-gray-800';
                    return 'border-primary-300 bg-primary-50 dark:border-primary-800 dark:bg-primary-950/30';
                },

                responsibilityLabel(value) {
                    return {
                        main_teacher: 'Yo',
                        specialist: 'Especialista',
                        shared: 'Compartida',
                        external: 'Externa',
                        unassigned: 'Por definir',
                    }[value] || 'Por definir';
                },

                typeLabel(value) {
                    return {
                        instructional: 'Clase / materia',
                        flexible: 'Flexible',
                        break: 'Recreo / descanso',
                        external: 'Actividad externa',
                    }[value] || 'Bloque';
                },

                openNew(day, start = null, end = null) {
                    this.editingIndex = null;
                    this.repeatActive = false;
                    this.localError = '';
                    const defaultStart = start || this.dayStart;
                    const defaultEnd = end || fromMinutes(Math.min(toMinutes(defaultStart) + 50, toMinutes(this.dayEnd)));
                    this.draft = {
                        day_of_week: Number(day),
                        starts_at: defaultStart,
                        ends_at: defaultEnd,
                        block_type: 'instructional',
                        label: '',
                        responsibility: 'main_teacher',
                        include_in_planning: true,
                        field_codes: [],
                        notes: '',
                    };
                    this.modalOpen = true;
                },

                openEdit(index) {
                    const block = this.blocks[index];
                    if (!block) return;
                    this.editingIndex = index;
                    this.repeatActive = false;
                    this.localError = '';
                    this.draft = JSON.parse(JSON.stringify(block));
                    this.modalOpen = true;
                },

                closeModal() {
                    this.modalOpen = false;
                    this.localError = '';
                },

                toggleField(code) {
                    const fields = Array.isArray(this.draft.field_codes) ? this.draft.field_codes : [];
                    this.draft.field_codes = fields.includes(code) ? fields.filter((x) => x !== code) : [...fields, code];
                },

                overlaps(candidate, ignoreIndex = null) {
                    const start = toMinutes(candidate.starts_at);
                    const end = toMinutes(candidate.ends_at);
                    return this.blocks.some((block, index) => {
                        if (index === ignoreIndex || Number(block.day_of_week) !== Number(candidate.day_of_week)) return false;
                        return start < toMinutes(block.ends_at) && end > toMinutes(block.starts_at);
                    });
                },

                saveDraft() {
                    this.localError = '';
                    const start = toMinutes(this.draft.starts_at);
                    const end = toMinutes(this.draft.ends_at);
                    if (!this.isActive(this.draft.day_of_week)) {
                        this.localError = 'Ese día no está activo en la jornada.';
                        return;
                    }
                    if (start < toMinutes(this.dayStart) || end > toMinutes(this.dayEnd) || end <= start) {
                        this.localError = 'El bloque debe quedar dentro de la jornada y terminar después de comenzar.';
                        return;
                    }
                    if (!String(this.draft.label || '').trim()) {
                        this.draft.label = this.draft.block_type === 'break' ? 'Recreo' : 'Clase';
                    }
                    if (['break','external'].includes(this.draft.block_type) || this.draft.responsibility === 'external') {
                        this.draft.include_in_planning = false;
                    }
                    if (this.overlaps(this.draft, this.editingIndex)) {
                        this.localError = 'Ese horario se traslapa con otro bloque del mismo día.';
                        return;
                    }

                    const clean = JSON.parse(JSON.stringify(this.draft));
                    if (this.editingIndex === null) {
                        this.blocks.push(clean);
                    } else {
                        this.blocks.splice(this.editingIndex, 1, clean);
                    }

                    if (this.repeatActive && this.editingIndex === null) {
                        for (const day of this.activeDays) {
                            if (Number(day) === Number(clean.day_of_week)) continue;
                            const copy = { ...JSON.parse(JSON.stringify(clean)), day_of_week: Number(day) };
                            if (!this.overlaps(copy)) this.blocks.push(copy);
                        }
                    }

                    this.blocks = [...this.blocks];
                    this.closeModal();
                    this.savedMessage = '';
                },

                deleteEditing() {
                    if (this.editingIndex === null) return;
                    this.blocks.splice(this.editingIndex, 1);
                    this.blocks = [...this.blocks];
                    this.closeModal();
                    this.savedMessage = '';
                },

                addBreakToActiveDays() {
                    const start = toMinutes(this.breakStart);
                    const end = toMinutes(this.breakEnd);
                    if (end <= start || start < toMinutes(this.dayStart) || end > toMinutes(this.dayEnd)) {
                        this.savedMessage = 'Revisa el horario del recreo.';
                        return;
                    }

                    let added = 0;
                    for (const day of this.activeDays) {
                        const candidate = {
                            day_of_week: Number(day),
                            sequence: 1,
                            starts_at: this.breakStart,
                            ends_at: this.breakEnd,
                            block_type: 'break',
                            label: 'Recreo',
                            responsibility: 'unassigned',
                            include_in_planning: false,
                            field_codes: [],
                            notes: '',
                        };
                        if (!this.overlaps(candidate)) {
                            this.blocks.push(candidate);
                            added++;
                        }
                    }
                    this.blocks = [...this.blocks];
                    this.savedMessage = added ? 'Recreo agregado a los días disponibles.' : 'El recreo se traslapa con bloques existentes.';
                },

                async saveAll() {
                    this.saving = true;
                    this.savedMessage = '';
                    try {
                        const snapshot = await this.$wire.saveSchedule({
                            name: this.name,
                            active_days: this.activeDays,
                            day_starts_at: this.dayStart,
                            day_ends_at: this.dayEnd,
                            blocks: this.blocks.map((block, index) => ({
                                ...block,
                                sequence: index + 1,
                            })),
                        });
                        if (snapshot) {
                            this.revision = Number(snapshot.revision || this.revision + 1);
                            this.blocks = (snapshot.blocks || []).map((b) => ({
                                ...b,
                                starts_at: normalizeTime(b.starts_at),
                                ends_at: normalizeTime(b.ends_at),
                            }));
                        }
                        this.savedMessage = 'Horario guardado. Se usará en las próximas planeaciones.';
                    } catch (error) {
                        this.savedMessage = 'No se pudo guardar. Revisa los horarios marcados.';
                    } finally {
                        this.saving = false;
                    }
                },
            };
        };
    </script>

    <div
        x-data="groupScheduleEditor(@js($initialSchedule), @js($fieldOptions))"
        class="space-y-6"
    >
        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
            <div>
                <p class="text-sm text-gray-600 dark:text-gray-300">
                    Configura la jornada real de <strong>{{ $groupName }}</strong>. Los espacios sin bloque fijo se consideran tiempo disponible para la planeación.
                </p>
                <p class="mt-1 text-xs text-gray-500">
                    Revisión <span x-text="revision"></span> · El horario se congela cuando confirmas una planeación.
                </p>
            </div>
            <div class="flex flex-wrap gap-2">
                <x-filament::button color="gray" tag="a" :href="$this->backUrl()" icon="heroicon-o-arrow-left">
                    Volver al grupo
                </x-filament::button>
                <x-filament::button x-on:click="saveAll()" x-bind:disabled="saving" icon="heroicon-o-check">
                    <span x-show="!saving">Guardar horario</span>
                    <span x-show="saving">Guardando…</span>
                </x-filament::button>
            </div>
        </div>

        <x-filament::section>
            <x-slot name="heading">Jornada habitual</x-slot>
            <x-slot name="description">Empieza con lo mínimo. No necesitas capturar cada espacio libre.</x-slot>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-[1fr_1fr_2fr]">
                <div>
                    <label class="mb-1 block text-sm font-medium">Entrada</label>
                    <input type="time" x-model="dayStart" class="block w-full rounded-lg border-gray-300 bg-white text-base shadow-sm dark:border-gray-700 dark:bg-gray-900">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium">Salida</label>
                    <input type="time" x-model="dayEnd" class="block w-full rounded-lg border-gray-300 bg-white text-base shadow-sm dark:border-gray-700 dark:bg-gray-900">
                </div>
                <div>
                    <span class="mb-1 block text-sm font-medium">Días de clase</span>
                    <div class="flex flex-wrap gap-2">
                        <template x-for="day in days" :key="day.value">
                            <button
                                type="button"
                                x-on:click="toggleDay(day.value)"
                                class="min-h-10 rounded-full border px-3 text-sm font-medium transition"
                                x-bind:class="isActive(day.value)
                                    ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300'
                                    : 'border-gray-300 text-gray-500 dark:border-gray-700 dark:text-gray-400'"
                                x-text="day.short"
                            ></button>
                        </template>
                    </div>
                </div>
            </div>

            <div class="mt-5 flex flex-col gap-3 rounded-xl bg-gray-50 p-4 dark:bg-gray-900/50 sm:flex-row sm:items-end">
                <div class="grid flex-1 grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Inicio de recreo</label>
                        <input type="time" x-model="breakStart" class="block w-full rounded-lg border-gray-300 bg-white text-base dark:border-gray-700 dark:bg-gray-900">
                    </div>
                    <div>
                        <label class="mb-1 block text-xs font-medium text-gray-600 dark:text-gray-300">Fin de recreo</label>
                        <input type="time" x-model="breakEnd" class="block w-full rounded-lg border-gray-300 bg-white text-base dark:border-gray-700 dark:bg-gray-900">
                    </div>
                </div>
                <x-filament::button color="gray" x-on:click="addBreakToActiveDays()" icon="heroicon-o-plus">
                    Agregar recreo a los días
                </x-filament::button>
            </div>

            <p x-show="savedMessage" x-text="savedMessage" class="mt-3 text-sm text-gray-600 dark:text-gray-300"></p>
        </x-filament::section>

        <div class="flex items-center justify-between gap-4">
            <div>
                <h2 class="text-base font-semibold">Semana</h2>
                <p class="text-sm text-gray-500">
                    Tiempo planeable aproximado:
                    <strong x-text="totalWeeklyMinutes() + ' min por semana'"></strong>
                </p>
            </div>
        </div>

        <div class="lg:hidden">
            <div class="mb-3 flex gap-2 overflow-x-auto pb-1">
                <template x-for="day in days.filter((d) => isActive(d.value))" :key="day.value">
                    <button
                        type="button"
                        x-on:click="selectedDay = day.value"
                        class="min-h-11 shrink-0 rounded-full border px-4 text-sm font-medium"
                        x-bind:class="selectedDay === day.value
                            ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300'
                            : 'border-gray-300 dark:border-gray-700'"
                        x-text="day.label"
                    ></button>
                </template>
            </div>

            <template x-for="day in days" :key="'mobile-' + day.value">
                <div x-show="selectedDay === day.value && isActive(day.value)" class="space-y-3">
                    <div class="flex items-center justify-between">
                        <div>
                            <h3 class="font-semibold" x-text="day.label"></h3>
                            <p class="text-xs text-gray-500" x-text="availableMinutes(day.value) + ' min planeables'"></p>
                        </div>
                        <x-filament::button size="sm" x-on:click="openNew(day.value)" icon="heroicon-o-plus">Bloque</x-filament::button>
                    </div>

                    <template x-for="(item, position) in timelineForDay(day.value)" :key="item.implicit ? 'gap-'+position+'-'+item.starts_at : 'block-'+item._index">
                        <button
                            type="button"
                            x-on:click="item.implicit ? openNew(day.value, item.starts_at, item.ends_at) : openEdit(item._index)"
                            class="block w-full rounded-xl border p-4 text-left"
                            x-bind:class="blockTone(item)"
                        >
                            <div class="flex items-start justify-between gap-3">
                                <div class="min-w-0">
                                    <p class="font-medium" x-text="item.label"></p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        <span x-text="item.starts_at"></span>–<span x-text="item.ends_at"></span>
                                        · <span x-text="item.duration + ' min'"></span>
                                    </p>
                                </div>
                                <span x-show="!item.implicit" class="text-xs text-gray-500" x-text="responsibilityLabel(item.responsibility)"></span>
                            </div>
                            <p x-show="item.implicit" class="mt-2 text-xs text-gray-500">Toca aquí para convertir parte de este espacio en una clase fija.</p>
                        </button>
                    </template>
                </div>
            </template>
        </div>

        <div class="hidden overflow-x-auto pb-2 lg:block">
            <div class="grid min-w-[980px] gap-4" style="grid-template-columns: repeat(5, minmax(180px, 1fr));">
                <template x-for="day in days.filter((d) => d.value <= 5)" :key="'desktop-'+day.value">
                    <section
                        class="min-w-0 rounded-xl border border-gray-200 p-3 dark:border-gray-800"
                        x-bind:class="!isActive(day.value) ? 'opacity-45' : ''"
                    >
                        <div class="mb-3 flex items-center justify-between gap-2">
                            <div>
                                <h3 class="font-semibold" x-text="day.label"></h3>
                                <p class="text-xs text-gray-500" x-show="isActive(day.value)" x-text="availableMinutes(day.value) + ' min planeables'"></p>
                            </div>
                            <button
                                type="button"
                                x-show="isActive(day.value)"
                                x-on:click="openNew(day.value)"
                                class="inline-flex h-9 w-9 items-center justify-center rounded-lg border border-gray-300 text-lg hover:bg-gray-50 dark:border-gray-700 dark:hover:bg-gray-900"
                                title="Agregar bloque"
                            >+</button>
                        </div>

                        <div x-show="isActive(day.value)" class="space-y-2">
                            <template x-for="(item, position) in timelineForDay(day.value)" :key="item.implicit ? 'gap-d-'+position+'-'+item.starts_at : 'block-d-'+item._index">
                                <button
                                    type="button"
                                    x-on:click="item.implicit ? openNew(day.value, item.starts_at, item.ends_at) : openEdit(item._index)"
                                    class="block w-full rounded-lg border p-3 text-left transition hover:ring-1 hover:ring-primary-300"
                                    x-bind:class="blockTone(item)"
                                >
                                    <p class="truncate text-sm font-medium" x-text="item.label"></p>
                                    <p class="mt-1 text-xs text-gray-500">
                                        <span x-text="item.starts_at"></span>–<span x-text="item.ends_at"></span>
                                        · <span x-text="item.duration + ' min'"></span>
                                    </p>
                                    <p x-show="!item.implicit" class="mt-1 text-[11px] text-gray-500" x-text="responsibilityLabel(item.responsibility)"></p>
                                </button>
                            </template>
                        </div>

                        <p x-show="!isActive(day.value)" class="py-8 text-center text-sm text-gray-500">Sin clases</p>
                    </section>
                </template>
            </div>

            <div x-show="activeDays.some((day) => day > 5)" class="mt-4 grid max-w-2xl gap-4 sm:grid-cols-2">
                <template x-for="day in days.filter((d) => d.value > 5 && isActive(d.value))" :key="'weekend-'+day.value">
                    <section class="rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                        <div class="mb-3 flex items-center justify-between">
                            <div>
                                <h3 class="font-semibold" x-text="day.label"></h3>
                                <p class="text-xs text-gray-500" x-text="availableMinutes(day.value) + ' min planeables'"></p>
                            </div>
                            <button type="button" x-on:click="openNew(day.value)" class="h-9 w-9 rounded-lg border border-gray-300 text-lg dark:border-gray-700">+</button>
                        </div>
                        <div class="space-y-2">
                            <template x-for="(item, position) in timelineForDay(day.value)" :key="item.implicit ? 'gap-w-'+position : 'block-w-'+item._index">
                                <button type="button" x-on:click="item.implicit ? openNew(day.value, item.starts_at, item.ends_at) : openEdit(item._index)" class="block w-full rounded-lg border p-3 text-left" x-bind:class="blockTone(item)">
                                    <p class="text-sm font-medium" x-text="item.label"></p>
                                    <p class="text-xs text-gray-500"><span x-text="item.starts_at"></span>–<span x-text="item.ends_at"></span></p>
                                </button>
                            </template>
                        </div>
                    </section>
                </template>
            </div>
        </div>

        <div
            x-cloak
            x-show="modalOpen"
            x-on:keydown.escape.window="closeModal()"
            class="fixed inset-0 z-50 flex items-end justify-center bg-black/40 p-0 sm:items-center sm:p-4"
        >
            <div x-on:click.outside="closeModal()" class="max-h-[92vh] w-full overflow-y-auto rounded-t-2xl bg-white p-5 shadow-xl dark:bg-gray-900 sm:max-w-xl sm:rounded-2xl">
                <div class="flex items-start justify-between gap-4">
                    <div>
                        <h3 class="text-lg font-semibold" x-text="editingIndex === null ? 'Agregar bloque' : 'Editar bloque'"></h3>
                        <p class="text-sm text-gray-500">Define sólo lo que realmente está fijo en el horario.</p>
                    </div>
                    <button type="button" x-on:click="closeModal()" class="h-10 w-10 rounded-lg border border-gray-300 text-xl dark:border-gray-700">×</button>
                </div>

                <div class="mt-5 space-y-5">
                    <div>
                        <label class="mb-1 block text-sm font-medium">Nombre visible</label>
                        <input x-model="draft.label" type="text" maxlength="160" placeholder="Ej. Matemáticas, Inglés, Educación Física…" class="block w-full rounded-lg border-gray-300 bg-white text-base dark:border-gray-700 dark:bg-gray-950">
                    </div>

                    <div class="grid grid-cols-2 gap-3">
                        <div>
                            <label class="mb-1 block text-sm font-medium">Inicio</label>
                            <input x-model="draft.starts_at" type="time" class="block w-full rounded-lg border-gray-300 bg-white text-base dark:border-gray-700 dark:bg-gray-950">
                        </div>
                        <div>
                            <label class="mb-1 block text-sm font-medium">Fin</label>
                            <input x-model="draft.ends_at" type="time" class="block w-full rounded-lg border-gray-300 bg-white text-base dark:border-gray-700 dark:bg-gray-950">
                        </div>
                    </div>

                    <div>
                        <span class="mb-2 block text-sm font-medium">Qué representa</span>
                        <div class="grid grid-cols-2 gap-2">
                            <template x-for="option in [
                                {value:'instructional', label:'Clase / materia'},
                                {value:'flexible', label:'Flexible'},
                                {value:'break', label:'Recreo'},
                                {value:'external', label:'Actividad externa'}
                            ]" :key="option.value">
                                <button
                                    type="button"
                                    x-on:click="draft.block_type = option.value"
                                    class="min-h-11 rounded-lg border px-3 text-sm"
                                    x-bind:class="draft.block_type === option.value ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' : 'border-gray-300 dark:border-gray-700'"
                                    x-text="option.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    <div x-show="draft.block_type !== 'break'">
                        <span class="mb-2 block text-sm font-medium">Quién lo imparte</span>
                        <div class="flex flex-wrap gap-2">
                            <template x-for="option in [
                                {value:'main_teacher', label:'Yo'},
                                {value:'specialist', label:'Otro profesor'},
                                {value:'shared', label:'Compartida'},
                                {value:'external', label:'Externa'},
                                {value:'unassigned', label:'Por definir'}
                            ]" :key="option.value">
                                <button
                                    type="button"
                                    x-on:click="draft.responsibility = option.value"
                                    class="min-h-10 rounded-full border px-3 text-sm"
                                    x-bind:class="draft.responsibility === option.value ? 'border-primary-500 bg-primary-50 text-primary-700 dark:bg-primary-950/40 dark:text-primary-300' : 'border-gray-300 dark:border-gray-700'"
                                    x-text="option.label"
                                ></button>
                            </template>
                        </div>
                    </div>

                    <label x-show="draft.block_type !== 'break' && draft.block_type !== 'external' && draft.responsibility !== 'external'" class="flex cursor-pointer items-start gap-3 rounded-xl border border-gray-200 p-3 dark:border-gray-800">
                        <input type="checkbox" x-model="draft.include_in_planning" class="mt-1 rounded border-gray-300 text-primary-600">
                        <span>
                            <span class="block text-sm font-medium">Considerar este tiempo en mis planeaciones</span>
                            <span class="block text-xs text-gray-500">Desactívalo si la clase aparece en el horario, pero no quieres que el sistema la planee.</span>
                        </span>
                    </label>

                    <div x-show="fieldOptions.length && draft.block_type === 'instructional'">
                        <span class="mb-2 block text-sm font-medium">Campo formativo relacionado <span class="font-normal text-gray-500">(opcional)</span></span>
                        <div class="space-y-2">
                            <template x-for="field in fieldOptions" :key="field.code">
                                <label class="flex cursor-pointer items-center gap-3 rounded-lg border border-gray-200 p-3 dark:border-gray-800">
                                    <input type="checkbox" x-bind:checked="(draft.field_codes || []).includes(field.code)" x-on:change="toggleField(field.code)" class="rounded border-gray-300 text-primary-600">
                                    <span class="text-sm" x-text="field.name"></span>
                                </label>
                            </template>
                        </div>
                        <p class="mt-2 text-xs text-gray-500">Puedes dejarlo sin relación. Esto permite horarios de escuelas privadas con Inglés, Robótica, Informática u otras clases.</p>
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium">Nota <span class="font-normal text-gray-500">(opcional)</span></label>
                        <textarea x-model="draft.notes" rows="2" maxlength="2000" class="block w-full rounded-lg border-gray-300 bg-white text-base dark:border-gray-700 dark:bg-gray-950" placeholder="Ej. La imparte el profesor de informática."></textarea>
                    </div>

                    <label x-show="editingIndex === null" class="flex cursor-pointer items-center gap-3">
                        <input type="checkbox" x-model="repeatActive" class="rounded border-gray-300 text-primary-600">
                        <span class="text-sm">Repetir este bloque en los demás días activos donde haya espacio</span>
                    </label>

                    <p x-show="localError" x-text="localError" class="rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-950/30 dark:text-danger-300"></p>

                    <div class="flex flex-wrap justify-between gap-3 pt-2">
                        <button x-show="editingIndex !== null" type="button" x-on:click="deleteEditing()" class="min-h-11 rounded-lg px-4 text-sm font-medium text-danger-600 hover:bg-danger-50 dark:hover:bg-danger-950/30">
                            Eliminar bloque
                        </button>
                        <div class="ml-auto flex gap-2">
                            <x-filament::button color="gray" x-on:click="closeModal()">Cancelar</x-filament::button>
                            <x-filament::button x-on:click="saveDraft()">Aplicar</x-filament::button>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</x-filament-panels::page>
