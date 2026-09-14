@php
    $planning = is_array($plan['planning'] ?? null) ? $plan['planning'] : [];
    $pedagogy = is_array($plan['pedagogical_design'] ?? null) ? $plan['pedagogical_design'] : [];
    $assessment = is_array($plan['assessment_plan'] ?? null) ? $plan['assessment_plan'] : [];
    $alignment = is_array($plan['curricular_alignment'] ?? null) ? $plan['curricular_alignment'] : [];
    $sessions = is_array($plan['sessions'] ?? null) ? $plan['sessions'] : [];
    $fields = is_array($alignment['fields'] ?? null) ? $alignment['fields'] : [];
    $contents = is_array($alignment['contents'] ?? null) ? $alignment['contents'] : [];
    $pdas = is_array($alignment['pdas'] ?? null) ? $alignment['pdas'] : [];
    $methodology = is_array($pedagogy['methodology'] ?? null) ? $pedagogy['methodology'] : [];
    $phases = is_array($methodology['phases'] ?? null) ? $methodology['phases'] : [];
    $sessionPreview = array_slice($sessions, 0, 3);
@endphp

<div class="pd-preview-shell">
    <div class="pd-preview-header">
        <div class="flex min-w-0 items-start gap-3">
            <span class="pd-icon-tile">
                <x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" />
            </span>
            <div class="min-w-0">
                <div class="pd-title">Vista previa de la planeación</div>
                <p class="mt-1 text-sm pd-muted">
                    Revisa lo esencial aquí. El documento descargable conserva la planeación completa y su formato final.
                </p>
            </div>
        </div>
        <div class="flex flex-wrap gap-2">
            <span class="pd-chip">{{ count($sessions) }} {{ count($sessions) === 1 ? 'sesión' : 'sesiones' }}</span>
            @if (! empty($planning['session_minutes']))
                <span class="pd-chip">{{ $planning['session_minutes'] }} min por sesión</span>
            @endif
        </div>
    </div>

    <div class="pd-preview-grid">
        <article class="pd-preview-card">
            <div class="pd-preview-card__heading">
                <x-filament::icon icon="heroicon-o-document" class="h-5 w-5" />
                <span>Resumen general</span>
            </div>

            <h3 class="text-base font-bold text-gray-950 dark:text-white">
                {{ $planning['title'] ?? 'Planeación didáctica' }}
            </h3>

            @if (! empty($pedagogy['purpose']))
                <p class="pd-preview-copy mt-2">
                    {{ \Illuminate\Support\Str::limit((string) $pedagogy['purpose'], 360) }}
                </p>
            @elseif (! empty($planning['project_name']))
                <p class="pd-preview-copy mt-2">Proyecto: {{ $planning['project_name'] }}</p>
            @endif

            <div class="mt-4 flex flex-wrap gap-2">
                @foreach (array_slice($phases, 0, 4) as $phase)
                    <span class="pd-chip">{{ $phase }}</span>
                @endforeach
                @if ($phases === [] && ! empty($methodology['name']))
                    <span class="pd-chip">{{ $methodology['name'] }}</span>
                @endif
            </div>
        </article>

        <article class="pd-preview-card">
            <div class="pd-preview-card__heading">
                <x-filament::icon icon="heroicon-o-squares-2x2" class="h-5 w-5" />
                <span>Campos y contenidos</span>
            </div>

            <div class="pd-info-list">
                @if ($fields !== [])
                    <div class="pd-info-row">
                        <span class="pd-icon-tile" style="width: 1.8rem; height: 1.8rem;">
                            <x-filament::icon icon="heroicon-o-book-open" class="h-4 w-4" />
                        </span>
                        <div>
                            <div class="pd-info-row__label">Campo formativo</div>
                            <div class="pd-info-row__value">{{ $fields[0]['name'] ?? $fields[0]['code'] ?? 'Configurado' }}</div>
                        </div>
                    </div>
                @endif

                @if ($contents !== [])
                    <div class="pd-info-row">
                        <span class="pd-icon-tile" style="width: 1.8rem; height: 1.8rem;">
                            <x-filament::icon icon="heroicon-o-clipboard-document-list" class="h-4 w-4" />
                        </span>
                        <div>
                            <div class="pd-info-row__label">Contenido</div>
                            <div class="pd-info-row__value">
                                {{ \Illuminate\Support\Str::limit((string) ($contents[0]['title'] ?? $contents[0]['full_text'] ?? 'Contenido seleccionado'), 150) }}
                            </div>
                        </div>
                    </div>
                @endif

                @if ($pdas !== [])
                    <div class="pd-info-row">
                        <span class="pd-icon-tile" style="width: 1.8rem; height: 1.8rem;">
                            <x-filament::icon icon="heroicon-o-bullseye" class="h-4 w-4" />
                        </span>
                        <div>
                            <div class="pd-info-row__label">PDA</div>
                            <div class="pd-info-row__value">
                                {{ \Illuminate\Support\Str::limit((string) ($pdas[0]['full_text'] ?? $pdas[0]['code'] ?? 'PDA seleccionado'), 150) }}
                            </div>
                        </div>
                    </div>
                @endif

                @if ($fields === [] && $contents === [] && $pdas === [])
                    <p class="pd-preview-copy">La alineación curricular está disponible dentro del documento final.</p>
                @endif
            </div>
        </article>

        <article class="pd-preview-card">
            <div class="pd-preview-card__heading">
                <x-filament::icon icon="heroicon-o-calendar-days" class="h-5 w-5" />
                <span>Sesiones ({{ count($sessions) }})</span>
            </div>

            <div class="pd-session-list">
                @forelse ($sessionPreview as $session)
                    <div class="pd-session-item">
                        <span class="pd-session-number">{{ $session['sequence'] ?? $loop->iteration }}</span>
                        <div class="min-w-0">
                            <div class="pd-session-title">{{ $session['title'] ?? 'Sesión ' . ($session['sequence'] ?? $loop->iteration) }}</div>
                            @if (! empty($session['specific_goal']))
                                <div class="pd-session-goal">{{ \Illuminate\Support\Str::limit((string) $session['specific_goal'], 105) }}</div>
                            @endif
                        </div>
                        <x-filament::icon icon="heroicon-o-chevron-right" class="h-4 w-4 text-gray-400" />
                    </div>
                @empty
                    <p class="pd-preview-copy">Las sesiones aparecerán aquí cuando estén disponibles.</p>
                @endforelse
            </div>

            @if (count($sessions) > 3)
                <div class="mt-3 text-xs font-semibold pd-muted">+ {{ count($sessions) - 3 }} sesiones adicionales en el documento.</div>
            @endif
        </article>
    </div>

    @if ($sessions !== [])
        <div class="px-4 pb-4">
            <details class="pd-details">
                <summary>
                    <span class="flex items-center gap-2">
                        <x-filament::icon icon="heroicon-o-list-bullet" class="h-4 w-4" />
                        Ver detalle de sesiones
                    </span>
                    <x-filament::icon icon="heroicon-o-chevron-down" class="h-4 w-4" />
                </summary>
                <div class="pd-details__body space-y-3">
                    @foreach ($sessions as $session)
                        @php($moments = is_array($session['moments'] ?? null) ? $session['moments'] : [])
                        <div class="pd-card-soft p-3">
                            <div class="flex flex-wrap items-start justify-between gap-2">
                                <div>
                                    <div class="text-sm font-bold text-gray-950 dark:text-white">
                                        Sesión {{ $session['sequence'] ?? $loop->iteration }} · {{ $session['title'] ?? 'Sin título' }}
                                    </div>
                                    @if (! empty($session['specific_goal']))
                                        <p class="mt-1 text-xs leading-5 pd-muted">{{ $session['specific_goal'] }}</p>
                                    @endif
                                </div>
                                @if (! empty($session['date']))
                                    <span class="pd-chip">{{ \Illuminate\Support\Carbon::parse($session['date'])->format('d/m/Y') }}</span>
                                @endif
                            </div>
                            <div class="mt-3 flex flex-wrap gap-2">
                                @foreach ($moments as $moment)
                                    <span class="pd-chip">
                                        {{ ucfirst((string) ($moment['type'] ?? 'Momento')) }}
                                        @if (isset($moment['minutes'])) · {{ $moment['minutes'] }} min @endif
                                    </span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </details>
        </div>
    @endif

    <div class="flex flex-wrap items-center justify-between gap-2 border-t border-gray-200 px-4 py-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
        <span>
            Versión {{ $version->number }} · {{ $version->status instanceof \BackedEnum ? $version->status->value : $version->status }}
        </span>
        @if (! empty($assessment['closure']))
            <span>Incluye evaluación formativa y cierre.</span>
        @endif
        @if ($renderRun)
            <span>Formato listo para descarga.</span>
        @endif
    </div>
</div>
