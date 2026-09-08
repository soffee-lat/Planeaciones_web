<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Empecemos por conocerte</x-slot>
        <p>Confirma cómo quieres que te llamemos. Más adelante podrás configurar tu escuela y tus grupos.</p>
        <form wire:submit="save" class="mt-6 space-y-4">
            <label for="name">Nombre</label>
            <x-filament::input.wrapper :valid="! $errors->has('name')">
                <x-filament::input id="name" wire:model="name" required maxlength="255" autocomplete="name" />
            </x-filament::input.wrapper>
            @error('name') <p role="alert">{{ $message }}</p> @enderror
            <x-filament::button type="submit">Guardar y continuar</x-filament::button>
        </form>
    </x-filament::section>
</x-filament-panels::page>

