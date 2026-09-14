<x-filament-panels::page>
    <div class="mx-auto max-w-2xl">
        <section class="pd-dashboard-card">
            <div class="flex items-start gap-3">
                <span class="pd-icon-tile">
                    <x-filament::icon icon="heroicon-o-user-circle" class="h-5 w-5" />
                </span>
                <div>
                    <div class="pd-eyebrow">Primer paso</div>
                    <h2 class="mt-1 text-2xl font-extrabold tracking-tight text-gray-950 dark:text-white">Empecemos por conocerte</h2>
                    <p class="mt-2 text-sm leading-6 pd-muted">Confirma cómo quieres que te llamemos. Después podrás configurar tu escuela y tus grupos.</p>
                </div>
            </div>

            <form wire:submit="save" class="mt-6 space-y-5">
                <div>
                    <label for="name" class="mb-2 block text-sm font-semibold text-gray-950 dark:text-white">Nombre</label>
                    <x-filament::input.wrapper :valid="! $errors->has('name')">
                        <x-filament::input id="name" wire:model="name" required maxlength="255" autocomplete="name" />
                    </x-filament::input.wrapper>
                    @error('name') <p role="alert" class="mt-2 text-sm text-danger-600">{{ $message }}</p> @enderror
                </div>

                <div class="flex items-center justify-end border-t border-gray-200 pt-4 dark:border-white/10">
                    <x-filament::button type="submit" size="lg" icon="heroicon-o-arrow-right" icon-position="after">Guardar y continuar</x-filament::button>
                </div>
            </form>
        </section>
    </div>
</x-filament-panels::page>
