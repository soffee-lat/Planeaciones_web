<x-filament-panels::page>
    <div
        x-data="scheduleEditor({
            dayStart: @js($dayStartsAt),
            dayEnd: @js($dayEndsAt),
            initialBlocks: @js($blocks)
        })"
        class="space-y-5"
    >
        <div class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900">
            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="text-sm font-medium">Entrada</label>
                    <input x-model="dayStart" type="time" class="fi-input mt-1 block rounded-lg border-gray-300" />
                </div>
                <div>
                    <label class="text-sm font-medium">Salida</label>
                    <input x-model="dayEnd" type="time" class="fi-input mt-1 block rounded-lg border-gray-300" />
                </div>
                <div class="min-w-0 flex-1 text-sm text-gray-500">
                    Construye la semana tocando <strong>+ Bloque</strong>. Los nombres son libres: Matemáticas, English,
                    Robótica, proyecto, etc. El campo formativo es opcional.
                </div>
                <x-filament::button type="button" x-on:click="save($wire)">Guardar horario</x-filament::button>
            </div>
        </div>

        <div class="md:hidden">
            <div class="flex gap-2 overflow-x-auto pb-2">
                <template x-for="day in days" :key="day.value">
                    <button
                        type="button"
                        x-on:click="activeDay = day.value"
                        class="rounded-full px-4 py-2 text-sm font-medium whitespace-nowrap"
                        :class="activeDay === day.value ? 'bg-primary-600 text-white' : 'bg-gray-100 text-gray-700 dark:bg-white/10 dark:text-gray-200'"
                        x-text="day.short"
                    ></button>
                </template>
            </div>
        </div>

        <div class="grid gap-3 md:grid-cols-5">
            <template x-for="day in days" :key="day.value">
                <section
                    class="min-w-0 rounded-xl border border-gray-200 bg-white p-3 dark:border-white/10 dark:bg-gray-900"
                    :class="activeDay !== day.value ? 'hidden md:block' : ''"
                >
                    <div class="mb-3 flex items-center justify-between gap-2">
                        <h3 class="font-semibold" x-text="day.label"></h3>
                        <button
                            type="button"
                            class="rounded-lg bg-primary-50 px-2.5 py-1.5 text-xs font-semibold text-primary-700 dark:bg-primary-500/10 dark:text-primary-300"
                            x-on:click="addBlock(day.value)"
                        >+ Bloque</button>
                    </div>

                    <div class="space-y-2">
                        <template x-for="(block, index) in blocksFor(day.value)" :key="block._key">
                            <article
                                class="rounded-lg border p-3"
                                :class="block.block_type === 'break' || block.include_in_planning === false
                                    ? 'border-dashed border-gray-300 bg-gray-50 dark:border-white/10 dark:bg-white/5'
                                    : 'border-gray-200 bg-white dark:border-white/10 dark:bg-gray-950'"
                            >
                                <button type="button" class="w-full text-left" x-on:click="editBlock(block)">
                                    <div class="flex items-start justify-between gap-2">
                                        <div class="min-w-0">
                                            <div class="truncate font-medium" x-text="block.label"></div>
                                            <div class="mt-1 text-xs text-gray-500">
                                                <span x-text="block.starts_at"></span>–<span x-text="block.ends_at"></span>
                                            </div>
                                        </div>
                                        <span
                                            class="rounded-full bg-gray-100 px-2 py-1 text-[11px] text-gray-600 dark:bg-white/10 dark:text-gray-300"
                                            x-text="responsibilityLabel(block.responsibility)"
                                        ></span>
                                    </div>
                                    <div class="mt-2 text-xs text-gray-500" x-show="block.is_flexible">Flexible para distribución pedagógica</div>
                                    <div class="mt-2 text-xs text-gray-500" x-show="!block.include_in_planning">Visible en jornada · no generar planeación</div>
                                </button>
                            </article>
                        </template>

                        <div
                            x-show="blocksFor(day.value).length === 0"
                            class="rounded-lg border border-dashed border-gray-300 p-5 text-center text-sm text-gray-500 dark:border-white/10"
                        >
                            Sin bloques todavía
                        </div>
                    </div>
                </section>
            </template>
        </div>

        <div
            x-show="editing"
            x-cloak
            class="rounded-xl border border-gray-200 bg-white p-4 dark:border-white/10 dark:bg-gray-900"
        >
            <div class="mb-4 flex items-center justify-between gap-3">
                <div>
                    <h3 class="font-semibold">Editar bloque</h3>
                    <p class="text-sm text-gray-500">Describe lo que realmente aparece en el horario de la escuela.</p>
                </div>
                <button type="button" class="text-sm text-gray-500" x-on:click="editing = null">Cerrar</button>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <label class="md:col-span-2">
                    <span class="text-sm font-medium">Nombre visible</span>
                    <input x-model="editing.label" type="text" maxlength="120" placeholder="Ej. Matemáticas, English, Educación Física" class="fi-input mt-1 block w-full rounded-lg border-gray-300" />
                </label>
                <label>
                    <span class="text-sm font-medium">Tipo</span>
                    <select x-model="editing.block_type" class="fi-select-input mt-1 block w-full rounded-lg border-gray-300">
                        <option value="class">Clase / espacio académico</option>
                        <option value="flexible">Flexible</option>
                        <option value="specialist">Clase con especialista</option>
                        <option value="activity">Actividad / taller</option>
                        <option value="break">Recreo / descanso</option>
                        <option value="unavailable">No disponible</option>
                    </select>
                </label>
                <label>
                    <span class="text-sm font-medium">Inicio</span>
                    <input x-model="editing.starts_at" type="time" class="fi-input mt-1 block w-full rounded-lg border-gray-300" />
                </label>
                <label>
                    <span class="text-sm font-medium">Fin</span>
                    <input x-model="editing.ends_at" type="time" class="fi-input mt-1 block w-full rounded-lg border-gray-300" />
                </label>
                <label>
                    <span class="text-sm font-medium">¿Quién lo imparte?</span>
                    <select x-model="editing.responsibility" class="fi-select-input mt-1 block w-full rounded-lg border-gray-300">
                        <option value="main_teacher">Yo / docente titular</option>
                        <option value="specialist">Otro docente / especialista</option>
                        <option value="shared">Compartida</option>
                        <option value="external">Actividad externa</option>
                        <option value="unassigned">Por definir</option>
                    </select>
                </label>
                <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <input x-model="editing.include_in_planning" type="checkbox" />
                    <span class="text-sm">Incluir en mis planeaciones</span>
                </label>
                <label class="flex items-center gap-2 rounded-lg border border-gray-200 p-3 dark:border-white/10">
                    <input x-model="editing.is_flexible" type="checkbox" />
                    <span class="text-sm">La IA puede decidir qué contenido colocar aquí</span>
                </label>
                <label>
                    <span class="text-sm font-medium">Campo(s) formativo(s), opcional</span>
                    <input
                        :value="(editing.field_codes || []).join(', ')"
                        x-on:change="editing.field_codes = $event.target.value.split(',').map(v => v.trim()).filter(Boolean)"
                        type="text"
                        placeholder="Ej. LEN, SPC"
                        class="fi-input mt-1 block w-full rounded-lg border-gray-300"
                    />
                </label>
                <label class="md:col-span-3">
                    <span class="text-sm font-medium">Nota opcional</span>
                    <input x-model="editing.notes" type="text" maxlength="1000" placeholder="Ej. La imparte la maestra de computación" class="fi-input mt-1 block w-full rounded-lg border-gray-300" />
                </label>
            </div>

            <div class="mt-4 flex flex-wrap justify-between gap-3">
                <x-filament::button color="danger" type="button" x-on:click="removeEditing()">Eliminar bloque</x-filament::button>
                <div class="flex gap-2">
                    <x-filament::button color="gray" type="button" x-on:click="duplicateEditing()">Duplicar</x-filament::button>
                    <x-filament::button type="button" x-on:click="normalize(); editing = null">Listo</x-filament::button>
                </div>
            </div>
        </div>

        <div class="rounded-xl bg-gray-50 p-4 text-sm text-gray-600 dark:bg-white/5 dark:text-gray-300">
            <strong>Cómo se usará:</strong>
            al confirmar una solicitud, Planeaciones congelará esta revisión del horario y construirá las fechas y bloques
            disponibles del periodo. Las clases con <em>Incluir en mis planeaciones</em> desactivado seguirán ocupando
            tiempo de la jornada, pero la IA no generará actividades para ellas.
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
                activeDay: 1,
                dayStart: config.dayStart || '08:00',
                dayEnd: config.dayEnd || '12:30',
                blocks: (config.initialBlocks || []).map((b, i) => ({ ...b, _key: 'saved-' + i + '-' + Date.now() })),
                editing: null,

                blocksFor(day) {
                    return this.blocks
                        .filter(b => Number(b.day_of_week) === Number(day))
                        .sort((a, b) => String(a.starts_at).localeCompare(String(b.starts_at)));
                },

                addBlock(day) {
                    const existing = this.blocksFor(day);
                    const start = existing.length ? existing[existing.length - 1].ends_at : this.dayStart;
                    const end = this.addMinutes(start, 50);
                    const block = {
                        _key: 'new-' + Date.now() + '-' + Math.random().toString(16).slice(2),
                        day_of_week: Number(day),
                        sequence: existing.length + 1,
                        starts_at: start,
                        ends_at: end > this.dayEnd ? this.dayEnd : end,
                        label: 'Nuevo bloque',
                        block_type: 'class',
                        responsibility: 'main_teacher',
                        include_in_planning: true,
                        is_flexible: false,
                        field_codes: [],
                        notes: null,
                    };
                    this.blocks.push(block);
                    this.editing = block;
                },

                editBlock(block) {
                    this.editing = block;
                },

                removeEditing() {
                    if (!this.editing) return;
                    const key = this.editing._key;
                    this.blocks = this.blocks.filter(b => b._key !== key);
                    this.editing = null;
                    this.normalize();
                },

                duplicateEditing() {
                    if (!this.editing) return;
                    const copy = JSON.parse(JSON.stringify(this.editing));
                    copy._key = 'copy-' + Date.now() + '-' + Math.random().toString(16).slice(2);
                    copy.starts_at = this.editing.ends_at;
                    copy.ends_at = this.addMinutes(copy.starts_at, this.duration(this.editing.starts_at, this.editing.ends_at));
                    if (copy.ends_at > this.dayEnd) copy.ends_at = this.dayEnd;
                    this.blocks.push(copy);
                    this.editing = copy;
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

                addMinutes(time, minutes) {
                    const total = Math.min(23 * 60 + 59, this.toMinutes(time) + minutes);
                    return String(Math.floor(total / 60)).padStart(2, '0') + ':' + String(total % 60).padStart(2, '0');
                },

                responsibilityLabel(value) {
                    return {
                        main_teacher: 'Titular',
                        specialist: 'Especialista',
                        shared: 'Compartida',
                        external: 'Externa',
                        unassigned: 'Por definir',
                    }[value] || 'Por definir';
                },

                save(wire) {
                    this.normalize();
                    const clean = this.blocks.map(({ _key, ...block }) => block);
                    wire.set('dayStartsAt', this.dayStart);
                    wire.set('dayEndsAt', this.dayEnd);
                    wire.set('blocks', clean).then(() => wire.saveSchedule());
                },
            };
        }
    </script>
</x-filament-panels::page>
