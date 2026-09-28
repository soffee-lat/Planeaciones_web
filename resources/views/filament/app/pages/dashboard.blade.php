<x-filament-panels::page>
    @php($eligibleGroups = \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::eligibleGroupOptions())

    <x-filament::section>
        <x-slot name="heading">Hola, {{ auth()->user()->name }}</x-slot>
        <p>Este es tu espacio personal para acompañarte en la organización de tus planeaciones.</p>

        @if (! auth()->user()->onboarding_completed_at)
            <div class="mt-4 rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-950/30">
                <strong>Primero completa los datos básicos de tu cuenta.</strong>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Te tomará sólo unos minutos y no tendrás que volver a capturarlos en cada planeación.</p>
                <div class="mt-4"><x-filament::button tag="a" href="{{ \App\Filament\App\Pages\Onboarding::getUrl() }}">Completar mi cuenta</x-filament::button></div>
            </div>
        @elseif (! auth()->user()->hasPedagogicalOnboardingComplete())
            <div class="mt-4 rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-950/30">
                <strong>Falta preparar tu información docente.</strong>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Necesitas una escuela y al menos un grupo con su perfil pedagógico. En “Mis grupos” verás exactamente qué dato falta.</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" href="{{ \App\Filament\App\Resources\Schools\SchoolResource::getUrl() }}">Mis escuelas</x-filament::button>
                    <x-filament::button tag="a" color="gray" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Revisar mis grupos</x-filament::button>
                </div>
            </div>
        @elseif ($eligibleGroups === [])
            <div class="mt-4 rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-950/30">
                <strong>Tu perfil está completo, pero todavía falta dejar un grupo listo para planear.</strong>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Normalmente sólo falta configurar el horario o marcar las materias que deben incluirse. En “Mis grupos” aparecerá una indicación junto a cada grupo.</p>
                <div class="mt-4">
                    <x-filament::button tag="a" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Ver qué me falta</x-filament::button>
                </div>
            </div>
        @else
            <div class="mt-4 rounded-xl border border-success-200 bg-success-50 p-4 dark:border-success-800 dark:bg-success-950/30">
                <strong>Todo listo para crear una planeación.</strong>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Ya tenemos tu grupo, perfil, currículo y horario. Sólo elige el periodo y los temas; el sistema te irá guiando.</p>
            </div>
            <div class="mt-6">
                <x-filament::button tag="a" size="lg" icon="heroicon-o-document-plus" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">
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
