<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Hola, {{ auth()->user()->name }}</x-slot>
        <p>Este es tu espacio personal para acompañarte en la organización de tus planeaciones.</p>
        @if (! auth()->user()->onboarding_completed_at)
        <div class="mt-6"><x-filament::button tag="a" href="{{ \App\Filament\App\Pages\Onboarding::getUrl() }}">Completar mi cuenta</x-filament::button></div>
        @elseif (! auth()->user()->hasPedagogicalOnboardingComplete())
        <p class="mt-4">Falta configurar tu perfil pedagógico. Agrega tu escuela, tu grupo y su perfil para personalizar tus planeaciones.</p>
        <div class="mt-4 flex flex-wrap gap-2">
            <x-filament::button tag="a" href="{{ \App\Filament\App\Resources\Schools\SchoolResource::getUrl() }}">Mis escuelas</x-filament::button>
            <x-filament::button tag="a" color="gray" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Mis grupos</x-filament::button>
        </div>
        @else
        <p class="mt-4">Tu cuenta y tu perfil pedagógico están listos.</p>
        <div class="mt-6">
            <x-filament::button tag="a" size="lg" icon="heroicon-o-document-plus" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl('create') }}">
                Nueva planeación
            </x-filament::button>
            <a class="ms-3 text-sm underline" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">Ver mis planeaciones</a>
        </div>
        @endif
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Tu cuenta, bajo tu control</x-slot>
        <p>Puedes actualizar tu nombre, correo y contraseña desde tu perfil.</p>
        <div class="mt-4"><x-filament::button tag="a" color="gray" href="{{ filament()->getProfileUrl() }}">Ver mi perfil</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>

