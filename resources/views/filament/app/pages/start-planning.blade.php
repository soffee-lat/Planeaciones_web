<x-filament-panels::page>
    <div class="mx-auto grid w-full max-w-5xl gap-6 lg:grid-cols-[minmax(0,1fr)_320px]">
        <x-filament::section>
            <x-slot name="heading">¿Qué necesitas trabajar?</x-slot>
            <x-slot name="description">Dinos lo mínimo. Primero te ayudaremos a conectar el currículo; la planeación se genera después de que tú confirmes esas conexiones.</x-slot>

            <form wire:submit="start" class="space-y-5">
                <div>
                    <label class="mb-2 block text-sm font-medium">Grupo</label>
                    <select wire:model.live="group_id" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        <option value="">Selecciona un grupo…</option>
                        @foreach ($groups as $id => $label)
                            <option value="{{ $id }}">{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('group_id') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="grid gap-4 sm:grid-cols-2">
                    <div>
                        <label class="mb-2 block text-sm font-medium">Desde</label>
                        <input type="date" wire:model="starts_on" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        @error('starts_on') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="mb-2 block text-sm font-medium">Hasta</label>
                        <input type="date" wire:model="ends_on" class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900">
                        @error('ends_on') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                    </div>
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">Tema, proyecto o necesidad</label>
                    <input
                        type="text"
                        wire:model="work_focus"
                        maxlength="255"
                        placeholder="Ej. Conocer mi cuerpo, multiplicación por 2, cuidado del agua…"
                        class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                    >
                    @error('work_focus') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label class="mb-2 block text-sm font-medium">Algo que debamos considerar <span class="font-normal text-gray-500">(opcional)</span></label>
                    <textarea
                        wire:model="context_note"
                        rows="4"
                        placeholder="Ej. Quiero integrar números y lenguaje; habrá una actividad especial el viernes."
                        class="block w-full rounded-lg border-gray-300 bg-white text-sm shadow-sm dark:border-gray-700 dark:bg-gray-900"
                    ></textarea>
                    @error('context_note') <p class="mt-1 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex flex-wrap items-center gap-3 pt-2">
                    <x-filament::button type="submit" size="lg" icon="heroicon-o-arrow-right" wire:loading.attr="disabled">
                        Ver conexiones curriculares
                    </x-filament::button>
                    <span class="text-sm text-gray-500" wire:loading>Preparando tu borrador…</span>
                </div>
            </form>
        </x-filament::section>

        <div class="space-y-6">
            <x-filament::section>
                <x-slot name="heading">No volveremos a preguntarte lo que ya sabemos</x-slot>
                @php($selectedGroup = $this->selectedGroup())
                @if ($selectedGroup)
                    <div class="space-y-2 text-sm">
                        <p><strong>{{ $selectedGroup->name }}</strong></p>
                        <p>Grado: {{ $selectedGroup->grade?->name ?? 'Configurado en tu grupo' }}</p>
                        @if ($selectedGroup->profile?->session_minutes)
                            <p>Sesiones habituales: {{ $selectedGroup->profile->session_minutes }} min</p>
                        @endif
                        <p class="text-gray-600 dark:text-gray-300">Usaremos automáticamente el perfil pedagógico, materiales, preferencias y restricciones que ya guardaste para este grupo.</p>
                    </div>
                @else
                    <p class="text-sm text-gray-600 dark:text-gray-300">Al seleccionar un grupo reutilizaremos su grado, currículo y perfil pedagógico. No necesitas capturarlos otra vez.</p>
                @endif
            </x-filament::section>

            <x-filament::section>
                <x-slot name="heading">Qué pasa después</x-slot>
                <ol class="space-y-3 text-sm text-gray-700 dark:text-gray-200">
                    <li><strong>1.</strong> Buscamos contenidos, PDA, campos y ejes relacionados.</li>
                    <li><strong>2.</strong> Tú aceptas, quitas o agregas conexiones.</li>
                    <li><strong>3.</strong> Sólo después generamos la planeación.</li>
                </ol>
            </x-filament::section>
        </div>
    </div>
</x-filament-panels::page>
