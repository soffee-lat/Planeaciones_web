@php
    $orderedDeliveries = $deliveries->sortByDesc(fn ($delivery) => $delivery->delivered_at?->getTimestamp() ?? $delivery->id)->values();
    $latestDelivery = $orderedDeliveries->first();
@endphp

@if ($latestDelivery)
    <div class="space-y-3">
        <div class="pd-status-banner">
            <div class="pd-status-banner__main">
                <span class="pd-status-banner__icon">
                    <x-filament::icon icon="heroicon-o-check" class="h-6 w-6" />
                </span>
                <div class="min-w-0">
                    <div class="pd-status-banner__title">Planeación lista</div>
                    <p class="pd-status-banner__description">
                        Tu planeación ha sido generada correctamente y está lista para descargar.
                    </p>
                    <div class="mt-2 flex flex-wrap gap-2">
                        <span class="pd-chip">Versión {{ $latestDelivery->version?->number ?? $latestDelivery->version_id }}</span>
                        @if ($latestDelivery->delivered_at)
                            <span class="pd-chip">{{ $latestDelivery->delivered_at->format('d/m/Y H:i') }}</span>
                        @endif
                    </div>
                </div>
            </div>

            <div class="pd-download-actions" aria-label="Descargas de la planeación">
                @foreach ($latestDelivery->files->sortBy(fn ($file) => strtolower((string) $file->pivot->output_format)) as $file)
                    @php
                        $expired = $file->purged_at !== null || ($file->retention_until !== null && $file->retention_until->isPast());
                        $format = strtolower((string) $file->pivot->output_format);
                        $label = strtoupper($format);
                        $isPdf = $format === 'pdf';
                    @endphp

                    @if ($expired)
                        <span class="pd-download-button pd-download-button--secondary opacity-60" aria-disabled="true">
                            <x-filament::icon icon="heroicon-o-clock" class="h-5 w-5" />
                            {{ $label }} expirado
                        </span>
                    @else
                        <a
                            href="{{ route('planning-deliveries.download', ['delivery' => $latestDelivery->id, 'file' => $file->id]) }}"
                            class="pd-download-button"
                        >
                            <x-filament::icon icon="{{ $isPdf ? 'heroicon-o-document-text' : 'heroicon-o-document-arrow-down' }}" class="h-5 w-5" />
                            Descargar {{ $label }}
                        </a>
                    @endif
                @endforeach
            </div>
        </div>

        @if ($orderedDeliveries->count() > 1)
            <details class="pd-details">
                <summary>
                    <span class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-clock" class="h-4 w-4" />
                        Ver entregas anteriores
                    </span>
                    <span class="pd-chip">{{ $orderedDeliveries->count() - 1 }}</span>
                </summary>
                <div class="pd-details__body space-y-3">
                    @foreach ($orderedDeliveries->slice(1) as $delivery)
                        <div class="pd-card-soft p-3">
                            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                <div>
                                    <div class="text-sm font-bold text-gray-950 dark:text-white">
                                        Entrega {{ $delivery->delivered_at?->format('d/m/Y H:i') }}
                                    </div>
                                    <div class="mt-1 text-xs pd-muted">Versión {{ $delivery->version?->number ?? $delivery->version_id }}</div>
                                </div>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                @foreach ($delivery->files as $file)
                                    @php
                                        $expired = $file->purged_at !== null || ($file->retention_until !== null && $file->retention_until->isPast());
                                        $label = strtoupper((string) $file->pivot->output_format);
                                    @endphp
                                    @if ($expired)
                                        <span class="pd-chip">{{ $label }} · expirado</span>
                                    @else
                                        <a
                                            href="{{ route('planning-deliveries.download', ['delivery' => $delivery->id, 'file' => $file->id]) }}"
                                            class="pd-chip hover:border-primary-400 hover:text-primary-600 dark:hover:text-primary-300"
                                        >Descargar {{ $label }}</a>
                                    @endif
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        @endif
    </div>
@endif
