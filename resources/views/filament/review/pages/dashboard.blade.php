<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Hola, {{ auth()->user()->name }}</x-slot>
        <p>Tu espacio de trabajo está preparado. Aquí podrás consultar los trabajos que se te asignen cuando esté disponible la revisión.</p>
        
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Tu cuenta, bajo tu control</x-slot>
        <p>Puedes actualizar tu nombre, correo y contraseña desde tu perfil.</p>
        <div class="mt-4"><x-filament::button tag="a" color="gray" href="{{ filament()->getProfileUrl() }}">Ver mi perfil</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>

