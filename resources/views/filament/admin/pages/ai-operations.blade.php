<x-filament-panels::page>
    @php
        $pendingExecutions = $this->pendingExecutions();
        $recentExecutions = $this->recentExecutions();
        $pendingOutbox = $this->pendingOutboxCount();
    @endphp

    @if (session('success') || session('warning') || session('error'))
        <x-filament::section>
            @if (session('success'))
                <div class="text-sm font-semibold text-success-600 dark:text-success-400">{{ session('success') }}</div>
            @endif
            @if (session('warning'))
                <div class="text-sm font-semibold text-warning-600 dark:text-warning-400">{{ session('warning') }}</div>
            @endif
            @if (session('error'))
                <div class="text-sm font-semibold text-danger-600 dark:text-danger-400">{{ session('error') }}</div>
            @endif
            @if (session('info'))
                <div class="mt-2 rounded-lg bg-primary-50 p-3 text-sm text-primary-800 dark:bg-primary-950/40 dark:text-primary-200">
                    {{ session('info') }}
                </div>
            @endif
        </x-filament::section>
    @endif

    @if ($errors->any())
        <x-filament::section>
            <div class="text-sm font-medium text-danger-600 dark:text-danger-400">{{ $errors->first() }}</div>
        </x-filament::section>
    @endif

    <x-filament::section>
        <x-slot name="heading">Cómo operar una planeación con IA manual</x-slot>
        <x-slot name="description">
            No necesitas memorizar el flujo. La tarjeta pendiente te indica qué paquete descargar, qué pedirle a la IA y qué resultado volver a subir.
        </x-slot>

        <div class="grid gap-3 md:grid-cols-4">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs font-bold uppercase tracking-wide text-gray-500">1 · Generación</div>
                <p class="mt-1 text-sm">La IA crea la primera versión de la planeación.</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs font-bold uppercase tracking-wide text-gray-500">2 · Auditoría</div>
                <p class="mt-1 text-sm">Otra ejecución valida currículo, PDA, horario y calidad.</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs font-bold uppercase tracking-wide text-gray-500">3 · Corrección</div>
                <p class="mt-1 text-sm">Sólo aparece si la auditoría encuentra algo corregible.</p>
            </div>
            <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                <div class="text-xs font-bold uppercase tracking-wide text-gray-500">4 · Reauditoría</div>
                <p class="mt-1 text-sm">Comprueba la versión corregida antes de aprobarla.</p>
            </div>
        </div>

        <p class="mt-4 text-sm text-gray-600 dark:text-gray-300">
            Las alertas del operador revisan pendientes cada 15 segundos. Actívalas una vez en este navegador para recibir sonido y notificación.
        </p>
    </x-filament::section>

    <div class="grid gap-4 md:grid-cols-3">
        <x-filament::section>
            <x-slot name="heading">Requieren tu acción</x-slot>
            <div class="text-3xl font-semibold">{{ $pendingExecutions->count() }}</div>
            <p class="mt-1 text-sm text-gray-500">Paquetes IA listos para procesar.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Preparándose</x-slot>
            <div class="text-3xl font-semibold">{{ $pendingOutbox }}</div>
            <p class="mt-1 text-sm text-gray-500">El scheduler los convierte en paquetes cada minuto.</p>
        </x-filament::section>

        <x-filament::section>
            <x-slot name="heading">Si no quieres esperar</x-slot>
            <form method="POST" action="{{ route('admin.ai-operations.prepare') }}">
                @csrf
                <x-filament::button type="submit" color="gray">Preparar pendientes ahora</x-filament::button>
            </form>
            <p class="mt-2 text-xs text-gray-500">Normalmente no necesitas usar este botón.</p>
        </x-filament::section>
    </div>

    <x-filament::section>
        <x-slot name="heading">Acción pendiente</x-slot>
        <x-slot name="description">
            Sigue los tres pasos de la tarjeta activa. Cuando importes un resultado, la siguiente etapa aparecerá con instrucciones nuevas.
        </x-slot>

        @if ($pendingExecutions->isEmpty())
            <div class="rounded-xl border border-dashed border-gray-300 p-8 text-center dark:border-gray-700">
                <div class="text-base font-medium">No hay trabajo manual pendiente.</div>
                <p class="mt-1 text-sm text-gray-500">Cuando una planeación requiera intervención aparecerá aquí automáticamente.</p>
            </div>
        @else
            <div class="space-y-6">
                @foreach ($pendingExecutions as $execution)
                    @php
                        $planningRequest = $execution->request;
                        $guide = $this->actionGuide($execution);
                        $steps = $this->workflowSteps($execution);
                        $history = $this->historyForRequest((int) $execution->request_id);
                        $clientCorrection = $this->clientCorrectionForExecution($execution);
                    @endphp

                    <div class="overflow-hidden rounded-2xl border-2 border-primary-200 bg-white shadow-sm dark:border-primary-800 dark:bg-gray-900">
                        <div class="border-b border-gray-200 bg-primary-50/60 p-5 dark:border-gray-700 dark:bg-primary-950/20">
                            <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                <div>
                                    <div class="flex flex-wrap items-center gap-2">
                                        <span class="text-lg font-semibold">Solicitud #{{ $execution->request_id }}</span>
                                        <span class="rounded-full bg-primary-100 px-2.5 py-1 text-xs font-bold text-primary-800 dark:bg-primary-900 dark:text-primary-200">
                                            {{ $this->executionLabel($execution) }}
                                        </span>
                                        <span class="rounded-full bg-warning-100 px-2.5 py-1 text-xs font-semibold text-warning-800 dark:bg-warning-900 dark:text-warning-200">
                                            Requiere tu acción
                                        </span>
                                    </div>
                                    <div class="mt-2 text-xl font-semibold">{{ $guide['headline'] }}</div>
                                    <p class="mt-1 max-w-3xl text-sm text-gray-600 dark:text-gray-300">{{ $guide['description'] }}</p>
                                </div>

                                <div class="text-sm text-gray-600 dark:text-gray-300">
                                    <div><span class="font-medium">Cliente:</span> {{ $planningRequest?->owner?->name ?? '—' }}</div>
                                    <div><span class="font-medium">Grupo:</span> {{ $planningRequest?->group?->name ?? '—' }}</div>
                                    <div><span class="font-medium">Periodo:</span> {{ $planningRequest?->period_label ?? '—' }}</div>
                                    <div><span class="font-medium">Execution:</span> #{{ $execution->id }}</div>
                                </div>
                            </div>
                        </div>

                        <div class="p-5">
                            @if ($clientCorrection)
                                <div class="mb-6 rounded-2xl border-2 border-warning-300 bg-warning-50 p-5 dark:border-warning-700 dark:bg-warning-950/30">
                                    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
                                        <div class="min-w-0">
                                            <div class="text-xs font-bold uppercase tracking-wide text-warning-700 dark:text-warning-300">
                                                Solicitud original del cliente · Revisión #{{ $clientCorrection->id }}
                                            </div>
                                            <div class="mt-2 text-lg font-semibold text-gray-950 dark:text-white">
                                                {{ $this->correctionReasonLabel($clientCorrection) }}
                                            </div>
                                            <p class="mt-2 whitespace-pre-wrap text-sm text-gray-800 dark:text-gray-100">{{ $clientCorrection->description }}</p>

                                            <div class="mt-4 flex flex-wrap gap-2">
                                                @foreach ($this->correctionSectionLabels($clientCorrection) as $section)
                                                    <span class="rounded-full bg-white px-2.5 py-1 text-xs font-semibold text-warning-800 ring-1 ring-warning-200 dark:bg-gray-900 dark:text-warning-200 dark:ring-warning-800">
                                                        {{ $section }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        </div>

                                        <div class="shrink-0 rounded-xl border border-warning-200 bg-white p-3 text-xs text-gray-600 dark:border-warning-800 dark:bg-gray-900 dark:text-gray-300">
                                            <div><span class="font-semibold">Solicitó:</span> {{ $clientCorrection->requester?->name ?? 'Cliente' }}</div>
                                            <div class="mt-1"><span class="font-semibold">Fecha:</span> {{ $clientCorrection->requested_at?->format('d/m/Y H:i') ?? '—' }}</div>
                                            <div class="mt-1"><span class="font-semibold">Versión origen:</span> #{{ $clientCorrection->source_version_id }}</div>
                                        </div>
                                    </div>

                                    <div class="mt-4 rounded-xl bg-white/80 p-3 text-sm text-gray-700 dark:bg-gray-900/80 dark:text-gray-200">
                                        <strong>Regla de esta operación:</strong>
                                        no regenerar la planeación ni reinterpretar el encargo. Modifica únicamente las secciones indicadas arriba según el comentario del cliente; conserva currículo, PDA, contexto, fechas y el resto de la versión entregada.
                                    </div>
                                </div>
                            @endif

                            <div class="mb-6">
                                <div class="mb-2 text-xs font-bold uppercase tracking-wide text-gray-500">Avance de esta planeación</div>
                                <div class="grid gap-2 md:grid-cols-5">
                                    @foreach ($steps as $index => $step)
                                        @php
                                            $stepClass = match ($step['status']) {
                                                'done' => 'border-success-200 bg-success-50 text-success-800 dark:border-success-800 dark:bg-success-950/30 dark:text-success-200',
                                                'current' => 'border-primary-300 bg-primary-50 text-primary-900 ring-2 ring-primary-200 dark:border-primary-700 dark:bg-primary-950/40 dark:text-primary-100',
                                                default => 'border-gray-200 bg-gray-50 text-gray-500 dark:border-gray-700 dark:bg-gray-950 dark:text-gray-400',
                                            };
                                        @endphp
                                        <div class="rounded-xl border p-3 {{ $stepClass }}">
                                            <div class="text-xs font-bold">{{ $index + 1 }}. {{ $step['label'] }}</div>
                                            @if ($step['status'] === 'current')
                                                <div class="mt-1 text-xs font-semibold">Estás aquí</div>
                                            @elseif ($step['status'] === 'done')
                                                <div class="mt-1 text-xs">Completado</div>
                                            @elseif ($step['detail'])
                                                <div class="mt-1 text-xs">{{ $step['detail'] }}</div>
                                            @endif
                                        </div>
                                    @endforeach
                                </div>
                            </div>

                            <div class="grid gap-4 lg:grid-cols-3">
                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                    <div class="text-xs font-bold uppercase tracking-wide text-primary-600">Paso 1</div>
                                    <div class="mt-1 font-semibold">Descargar entrada</div>
                                    <p class="mt-1 text-sm text-gray-500">
                                        Paquete de {{ number_format(($execution->manualPackage?->size_bytes ?? 0) / 1024, 1) }} KB preparado por Soffee.
                                    </p>
                                    <x-filament::button
                                        tag="a"
                                        color="primary"
                                        class="mt-4"
                                        href="{{ route('admin.ai-operations.download', $execution) }}"
                                    >
                                        {{ $guide['download'] }}
                                    </x-filament::button>
                                </div>

                                <div class="rounded-xl border border-gray-200 p-4 dark:border-gray-700">
                                    <div class="text-xs font-bold uppercase tracking-wide text-primary-600">Paso 2</div>
                                    <div class="mt-1 font-semibold">Procesar con IA</div>
                                    <p class="mt-2 text-sm text-gray-700 dark:text-gray-200">{{ $guide['process'] }}</p>
                                    <div class="mt-3 rounded-lg bg-warning-50 p-3 text-xs text-warning-800 dark:bg-warning-950/30 dark:text-warning-200">
                                        El archivo descargado es la <strong>entrada</strong>. Aquí debes volver con un archivo de <strong>resultado</strong> distinto.
                                    </div>
                                </div>

                                <div class="rounded-xl border border-success-200 bg-success-50/40 p-4 dark:border-success-800 dark:bg-success-950/20">
                                    <div class="text-xs font-bold uppercase tracking-wide text-success-700 dark:text-success-300">Paso 3</div>
                                    <div class="mt-1 font-semibold">{{ $guide['upload'] }}</div>

                                    <form
                                        method="POST"
                                        enctype="multipart/form-data"
                                        action="{{ route('admin.ai-operations.result', $execution) }}"
                                        class="mt-3"
                                    >
                                        @csrf

                                        <input
                                            type="file"
                                            name="result_file"
                                            accept=".json,application/json"
                                            required
                                            class="block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                                        >

                                        <details class="mt-3 rounded-lg border border-gray-200 bg-white p-3 dark:border-gray-700 dark:bg-gray-900">
                                            <summary class="cursor-pointer text-xs font-semibold text-gray-600 dark:text-gray-300">
                                                Registrar proveedor/modelo (opcional)
                                            </summary>
                                            <div class="mt-3 space-y-3">
                                                <label class="block">
                                                    <span class="text-xs font-medium">Proveedor</span>
                                                    <select name="provider" class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900">
                                                        <option value="">No registrar</option>
                                                        <option value="OpenAI / ChatGPT">OpenAI / ChatGPT</option>
                                                        <option value="Google / Gemini">Google / Gemini</option>
                                                        <option value="Anthropic / Claude">Anthropic / Claude</option>
                                                        <option value="Otro">Otro</option>
                                                    </select>
                                                </label>
                                                <label class="block">
                                                    <span class="text-xs font-medium">Modelo</span>
                                                    <input
                                                        type="text"
                                                        name="model"
                                                        maxlength="128"
                                                        placeholder="Ej. GPT-5.6 Sol"
                                                        class="mt-1 block w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm dark:border-gray-700 dark:bg-gray-900"
                                                    >
                                                </label>
                                            </div>
                                        </details>

                                        <x-filament::button type="submit" color="success" class="mt-4 w-full">
                                            {{ $guide['submit'] }}
                                        </x-filament::button>
                                    </form>
                                </div>
                            </div>

                            <div class="mt-4 rounded-xl border border-primary-200 bg-primary-50 p-4 text-sm text-primary-900 dark:border-primary-800 dark:bg-primary-950/30 dark:text-primary-100">
                                <strong>Qué ocurrirá después:</strong> {{ $guide['next'] }}
                            </div>

                            <details class="mt-4">
                                <summary class="cursor-pointer text-sm font-semibold text-gray-600 dark:text-gray-300">
                                    Ver historial técnico de esta solicitud ({{ $history->count() }} ejecuciones)
                                </summary>
                                <div class="mt-3 flex flex-wrap gap-2">
                                    @foreach ($history as $item)
                                        <span class="rounded-full border border-gray-200 px-3 py-1 text-xs dark:border-gray-700">
                                            #{{ $item->id }} · {{ $this->executionLabel($item) }} · {{ $item->status->value }}
                                        </span>
                                    @endforeach
                                </div>
                            </details>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    </x-filament::section>

    <x-filament::section>
        <x-slot name="heading">Historial reciente del pipeline</x-slot>
        <x-slot name="description">Registro para trazabilidad; la acción pendiente siempre aparece arriba con instrucciones.</x-slot>

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
                            <th class="px-3 py-2">Resultado</th>
                            <th class="px-3 py-2">Proveedor</th>
                            <th class="px-3 py-2">Finalizada</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recentExecutions as $execution)
                            <tr class="border-b border-gray-100 dark:border-gray-800">
                                <td class="px-3 py-3">#{{ $execution->id }}</td>
                                <td class="px-3 py-3">#{{ $execution->request_id }} · {{ $execution->request?->owner?->name ?? '—' }}</td>
                                <td class="px-3 py-3">{{ $this->executionLabel($execution) }}</td>
                                <td class="px-3 py-3 font-medium">{{ $this->resultLabel($execution) }}</td>
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
