<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Hola, {{ auth()->user()->name }}</x-slot>
        <p>Este es tu espacio personal para acompañarte en la organización de tus planeaciones.</p>
        @if (! auth()->user()->onboarding_completed_at)
        <div class="mt-6"><x-filament::button tag="a" href="{{ \App\Filament\App\Pages\Onboarding::getUrl() }}">Completar mi cuenta</x-filament::button></div>
        @else
        <p class="mt-4">Tu cuenta está preparada. Próximamente podrás configurar tu escuela y tus grupos.</p>
        @endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Tu cuenta, bajo tu control</x-slot>
        <p>Puedes actualizar tu nombre, correo y contraseña desde tu perfil.</p>
        <div class="mt-4"><x-filament::button tag="a" color="gray" href="{{ filament()->getProfileUrl() }}">Ver mi perfil</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>

