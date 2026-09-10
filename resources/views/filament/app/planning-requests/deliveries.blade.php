<div class="space-y-3">
    <div>
        <h3 class="text-base font-semibold">Archivos de tu planeación</h3>
        <p class="text-sm text-gray-500">Cada entrega conserva la versión exacta que estuvo disponible en ese momento.</p>
    </div>

    @foreach ($deliveries as $delivery)
        <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <div>
                    <div class="font-medium">Entrega {{ $delivery->delivered_at?->format('d/m/Y H:i') }}</div>
                    <div class="text-xs text-gray-500">Versión {{ $delivery->version?->number ?? $delivery->version_id }}</div>
                </div>
            </div>
            <div class="flex flex-wrap gap-2">
                @foreach ($delivery->files as $file)
                    @php
                        $expired = $file->purged_at !== null || ($file->retention_until !== null && $file->retention_until->isPast());
                        $label = strtoupper((string) $file->pivot->output_format);
                    @endphp
                    @if ($expired)
                        <span class="rounded-lg bg-gray-100 px-3 py-2 text-sm text-gray-500 dark:bg-white/5">{{ $label }} · expirado</span>
                    @else
                        <a
                            href="{{ route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]) }}"
                            class="rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                        >Descargar {{ $label }}</a>
                    @endif
                @endforeach
            </div>
        </div>
    @endforeach
</div>
