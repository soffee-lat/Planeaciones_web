<x-filament-panels::page>
    @php
        $pending = $this->pendingCorrections();
        $recent = $this->recentCorrections();
    @endphp

    <x-filament::section>
        <x-slot name="heading">Bandeja de revisiones solicitadas por clientes</x-slot>
        <x-slot name="description">
            Aquí aparecen las correcciones que un cliente pidió después de recibir su planeación.
            Ninguna ronda se consume hasta que aceptes procesarla.
        </x-slot>

        <div class="grid gap-4 md:grid-cols-3">
            <div class="rounded-xl border border-warning-200 bg-warning-50 p-4 dark:border-warning-800 dark:bg-warning-950/30">
                <div class="text-xs font-semibold uppercase tracking-wide text-warning-700 dark:text-warning-300">Esperando decisión</div>
                <div class="mt-1 text-3xl font-semibold">{{ $pending->count() }}</div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">Solicitudes que necesitan que las revises.</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-sm font-semibold">Si aceptas</div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Se consume una ronda incluida y se crea automáticamente una Corrección IA con reauditoría.
                </p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-sm font-semibold">Si rechazas</div>
                <p class="mt-1 text-sm text-gray-600 dark:text-gray-300">
                    Debes indicar el motivo. La entrega anterior permanece disponible y no se consume una ronda.
                </p>
            </div>
        </div>
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Pendientes</x-slot>

        @if ($pending->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                <div class="text-base font-medium">No hay revisiones esperando decisión.</div>
                <p class="mt-1 text-sm text-gray-500">Cuando un cliente solicite una corrección aparecerá aquí y recibirás una alerta.</p>
            </div>
        @else
            <div class="space-y-5">
                @foreach ($pending as $correction)
                    <div class="rounded-xl border-2 border-warning-200 bg-white p-5 shadow-sm dark:border-warning-800 dark:bg-gray-900">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div class="min-w-0">
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-lg font-semibold">Revisión #{{ $correction->id }}</span>
                                    <span class="rounded-full bg-warning-100 px-2.5 py-1 text-xs font-semibold text-warning-800 dark:bg-warning-900 dark:text-warning-200">
                                        Esperando tu decisión
                                    </span>
                                </div>

                                <div class="mt-3 grid gap-x-8 gap-y-1 text-sm text-gray-600 md:grid-cols-2 dark:text-gray-300">
                                    <div><span class="font-medium">Cliente:</span> {{ $correction->requester?->name ?? $correction->request?->owner?->name ?? '—' }}</div>
                                    <div><span class="font-medium">Planeación:</span> #{{ $correction->request_id }} · {{ $correction->request?->project ?? '—' }}</div>
                                    <div><span class="font-medium">Grupo:</span> {{ $correction->request?->group?->name ?? '—' }}</div>
                                    <div><span class="font-medium">Solicitada:</span> {{ $correction->requested_at?->format('d/m/Y H:i') ?? '—' }}</div>
                                    <div class="md:col-span-2"><span class="font-medium">Motivo:</span> {{ $this->reasonLabel($correction) }}</div>
                                </div>

                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($this->sectionLabels($correction) as $section)
                                        <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-medium text-gray-700 dark:bg-gray-800 dark:text-gray-200">
                                            {{ $section }}
                                        </span>
                                    @endforeach
                                </div>

                                <div class="mt-4 rounded-lg border border-gray-200 bg-gray-50 p-4 dark:border-gray-700 dark:bg-gray-950">
                                    <div class="text-xs font-semibold uppercase tracking-wide text-gray-500">Lo que pidió el cliente</div>
                                    <p class="mt-1 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100">{{ $correction->description }}</p>
                                </div>
                            </div>

                            <div class="shrink-0">
                                <x-filament::button
                                    tag="a"
                                    color="warning"
                                    href="{{ $this->requestUrl($correction) }}"
                                >
                                    Revisar y decidir
                                </x-filament::button>
                                <p class="mt-2 max-w-48 text-xs text-gray-500">
                                    En la solicitud podrás aceptar y enviar a Corrección IA o rechazar indicando el motivo.
                                </p>
                            </div>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Historial reciente</x-slot>

        @if ($recent->isEmpty())
            <p class="text-sm text-gray-500">Todavía no hay revisiones procesadas.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700">
                        <tr>
                            <th class="px-3 py-2">Revisión</th>
                            <th class="px-3 py-2">Planeación</th>
                            <th class="px-3 py-2">Cliente</th>
                            <th class="px-3 py-2">Estado</th>
                            <th class="px-3 py-2">Solicitada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recent as $correction)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-3">#{{ $correction->id }}</td>
                                <td class="px-3 py-3">#{{ $correction->request_id }} · {{ $correction->request?->project ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $correction->requester?->name ?? $correction->request?->owner?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $this->statusLabel($correction->status) }}</td>
                                <td class="px-3 py-3">{{ $correction->requested_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
