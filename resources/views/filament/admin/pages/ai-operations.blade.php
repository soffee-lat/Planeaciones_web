<x-filament-panels::page>
    @php
        $pendingExecutions = $this->pendingExecutions();
        $recentExecutions = $this->recentExecutions();
        $pendingOutbox = $this->pendingOutboxCount();
    @endphp

    @if (session('success'))
        <x-filament::section>
            <div class="text-sm font-medium text-success-600 dark:text-success-400">
                {{ session('success') }}
            </div>
            @if (session('info'))
                <div class="mt-1 text-sm text-gray-600 dark:text-gray-300">{{ session('info') }}</div>
            @endif
        </x-filament::section>
    @endif

    @if (session('warning'))
        <x-filament::section>
            <div class="text-sm font-medium text-warning-600 dark:text-warning-400">
                {{ session('warning') }}
            </div>
        </x-filament::section>
    @endif

    @if (session('error'))
        <x-filament::section>
            <div class="text-sm font-medium text-danger-600 dark:text-danger-400">
                {{ session('error') }}
            </div>
        </x-filament::section>
    @endif

    @if ($errors->any())
        <x-filament::section>
            <div class="text-sm font-medium text-danger-600 dark:text-danger-400">
                {{ $errors->first() }}
            </div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Alertas del operador</x-slot>
        <p class="text-sm text-gray-600 dark:text-gray-300">
            En este navegador pulsa <strong>Activar alertas IA</strong> una vez. El panel revisa pendientes cada 15 segundos,
            muestra aviso persistente, cambia el título de la pestaña y repite sonido/notificación hasta que marques el trabajo como visto.
        </p>
        <p class="mt-2 text-xs text-gray-500">
            Para recibir estos avisos esta versión requiere mantener abierta alguna pestaña del panel de administración.
        </p>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Esperando proveedor</x-slot>
            <div class="text-3xl font-semibold">{{ $pendingExecutions->count() }}</div>
            <p class="mt-1 text-sm text-gray-500">Paquetes listos para descargar y procesar.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Outbox pendiente</x-slot>
            <div class="text-3xl font-semibold">{{ $pendingOutbox }}</div>
            <p class="mt-1 text-sm text-gray-500">El scheduler los prepara cada minuto.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Acción de respaldo</x-slot>
            <form method="POST" action="{{ route('admin.ai-operations.prepare') }}">
                @csrf
                <x-filament::button type="submit" color="gray">
                    Preparar pendientes ahora
                </x-filament::button>
            </form>
            <p class="mt-2 text-xs text-gray-500">Úsalo solo si no quieres esperar al scheduler.</p>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Trabajo pendiente</x-slot>
        <x-slot name="description">
            Descarga el paquete, súbelo al proveedor de IA y carga aquí el JSON resultante.
        </x-slot>

        @if ($pendingExecutions->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                <div class="text-base font-medium">No hay trabajo manual pendiente.</div>
                <p class="mt-1 text-sm text-gray-500">Cuando una planeación requiera tu intervención aparecerá aquí y recibirás una alerta.</p>
            </div>
        @else
            <div class="space-y-5">
                @foreach ($pendingExecutions as $execution)
                    @php
                        $planningRequest = $execution->request;
                        $stage = $execution->stage;
                    @endphp

                    <div class="rounded-xl border border-gray-200 p-5 dark:border-gray-700">
                        <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                            <div>
                                <div class="flex flex-wrap items-center gap-2">
                                    <span class="text-lg font-semibold">
                                        Solicitud #{{ $execution->request_id }}
                                    </span>
                                    <span class="rounded-full bg-gray-100 px-2.5 py-1 text-xs font-semibold dark:bg-gray-800">
                                        {{ $this->stageLabel($stage) }}
                                    </span>
                                    <span class="rounded-full bg-warning-50 px-2.5 py-1 text-xs font-semibold text-warning-700 dark:bg-warning-950 dark:text-warning-300">
                                        Esperando procesamiento
                                    </span>
                                </div>

                                <div class="mt-2 grid gap-x-6 gap-y-1 text-sm text-gray-600 sm:grid-cols-2 dark:text-gray-300">
                                    <div><span class="font-medium">Execution:</span> #{{ $execution->id }}</div>
                                    <div><span class="font-medium">Cliente:</span> {{ $planningRequest?->owner?->name ?? '—' }}</div>
                                    <div><span class="font-medium">Grupo:</span> {{ $planningRequest?->group?->name ?? '—' }}</div>
                                    <div><span class="font-medium">Periodo:</span> {{ $planningRequest?->period_label ?? '—' }}</div>
                                    <div><span class="font-medium">Creada:</span> {{ $execution->created_at?->format('d/m/Y H:i') }}</div>
                                    <div><span class="font-medium">Paquete:</span> {{ number_format(($execution->manualPackage?->size_bytes ?? 0) / 1024, 1) }} KB</div>
                                </div>
                            </div>

                            <x-filament::button
                                tag="a"
                                color="primary"
                                href="{{ route('admin.ai-operations.download', $execution) }}"
                            >
                                Descargar paquete
                            </x-filament::button>
                        </div>

                        <form
                            method="POST"
                            enctype="multipart/form-data"
                            action="{{ route('admin.ai-operations.result', $execution) }}"
                            class="mt-5 border-t border-gray-200 pt-5 dark:border-gray-700"
                        >
                            @csrf

                            <div class="grid gap-4 lg:grid-cols-3">
                                <label class="block lg:col-span-1">
                                    <span class="text-sm font-medium">Resultado JSON</span>
                                    <input
                                        type="file"
                                        name="result_file"
                                        accept=".json,application/json"
                                        required
                                        class="mt-2 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                                    >
                                </label>

                                <label class="block">
                                    <span class="text-sm font-medium">Proveedor <span class="font-normal text-gray-500">(opcional)</span></span>
                                    <select
                                        name="provider"
                                        class="mt-2 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                                    >
                                        <option value="">No registrar</option>
                                        <option value="OpenAI / ChatGPT">OpenAI / ChatGPT</option>
                                        <option value="Google / Gemini">Google / Gemini</option>
                                        <option value="Anthropic / Claude">Anthropic / Claude</option>
                                        <option value="Otro">Otro</option>
                                    </select>
                                </label>

                                <label class="block">
                                    <span class="text-sm font-medium">Modelo <span class="font-normal text-gray-500">(si registras proveedor)</span></span>
                                    <input
                                        type="text"
                                        name="model"
                                        maxlength="128"
                                        placeholder="Ej. GPT-5.6 Sol"
                                        class="mt-2 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                                    >
                                </label>
                            </div>

                            <div class="mt-4 flex items-center gap-3">
                                <x-filament::button type="submit" color="success">
                                    Importar, validar y continuar
                                </x-filament::button>
                                <span class="text-xs text-gray-500">
                                    El sistema aplicará el mismo schema, snapshot, currículo e idempotencia que el flujo por API.
                                </span>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Procesadas recientemente</x-slot>

        @if ($recentExecutions->isEmpty())
            <p class="text-sm text-gray-500">Todavía no hay ejecuciones manuales completadas.</p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="border-b border-gray-200 text-xs uppercase text-gray-500 dark:border-gray-700">
                        <tr>
                            <th class="px-3 py-2">Execution</th>
                            <th class="px-3 py-2">Solicitud</th>
                            <th class="px-3 py-2">Etapa</th>
                            <th class="px-3 py-2">Proveedor</th>
                            <th class="px-3 py-2">Finalizada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentExecutions as $execution)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-3">#{{ $execution->id }}</td>
                                <td class="px-3 py-3">#{{ $execution->request_id }} · {{ $execution->request?->owner?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $this->stageLabel($execution->stage) }}</td>
                                <td class="px-3 py-3">
                                    {{ $execution->provider ?: 'No registrado' }}
                                    @if ($execution->model)
                                        <div class="text-xs text-gray-500">{{ $execution->model }}</div>
                                    @endif
                                </td>
                                <td class="px-3 py-3">{{ $execution->finished_at?->format('d/m/Y H:i') ?? '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </x-filament::section>
</x-filament-panels::page>
