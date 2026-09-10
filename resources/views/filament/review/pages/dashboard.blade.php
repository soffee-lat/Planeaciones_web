<x-filament-panels::page>
    <x-filament::section>
        <x-slot name="heading">Hola, {{ auth()->user()->name }}</x-slot>
        <p>Consulta tus trabajos desde “Mis revisiones”. Una asignación activa muestra solo la versión pedagógica necesaria para revisar, su checklist y la fecha límite.</p>
        
    </x-filament::section>
    <x-filament::section>
        <x-slot name="heading">Mi trabajo y honorarios</x-slot>
        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            <div><div class="text-sm text-gray-500">Carga activa</div><div class="text-2xl font-semibold">{{ $reviewerMetrics['active_units'] }} U</div></div>
            <div><div class="text-sm text-gray-500">Revisiones completadas</div><div class="text-2xl font-semibold">{{ $reviewerMetrics['completed_assignments'] }}</div></div>
            <div><div class="text-sm text-gray-500">Trabajos por liquidar</div><div class="text-2xl font-semibold">{{ $reviewerMetrics['payable_items'] }}</div></div>
            <div><div class="text-sm text-gray-500">Trabajos pagados</div><div class="text-2xl font-semibold">{{ $reviewerMetrics['paid_items'] }}</div></div>
        </div>
        @if ($reviewerMetrics['amounts'])
            <div class="mt-4 space-y-1 text-sm">
                @foreach ($reviewerMetrics['amounts'] as $currency => $amount)
                    <div><strong>{{ $currency }}</strong>: por liquidar {{ number_format($amount['approved_minor'] / 100, 2) }} · pagado {{ number_format($amount['paid_minor'] / 100, 2) }}</div>
                @endforeach
            </div>
        @endif
        <p class="mt-4 text-sm text-gray-500">Las correcciones internas del mismo ciclo no generan un segundo honorario automático.</p>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Tu cuenta, bajo tu control</x-slot>
        <p>Puedes actualizar tu nombre, correo y contraseña desde tu perfil.</p>
        <div class="mt-4"><x-filament::button tag="a" color="gray" href="{{ filament()->getProfileUrl() }}">Ver mi perfil</x-filament::button></div>
    </x-filament::section>
</x-filament-panels::page>

