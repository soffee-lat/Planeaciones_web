<x-filament-panels::page>
    <div class="pd-dashboard">
        <section class="pd-dashboard-hero">
            <div>
                <div class="pd-eyebrow">Revisión pedagógica</div>
                <h2 class="pd-dashboard-hero__title">Hola, {{ auth()->user()->name }}</h2>
                <p class="pd-dashboard-hero__copy">
                    Consulta tus asignaciones activas, revisa únicamente la versión pedagógica necesaria y da seguimiento a tu carga y honorarios desde un mismo espacio.
                </p>
                <div class="pd-dashboard-hero__actions">
                    <x-filament::button tag="a" icon="heroicon-o-clipboard-document-check" href="{{ \App\Filament\Review\Resources\Assignments\ReviewAssignmentResource::getUrl() }}">
                        Ver mis revisiones
                    </x-filament::button>
                </div>
            </div>

            <aside class="pd-dashboard-hero__aside">
                <div class="flex items-center gap-3">
                    <span class="pd-icon-tile">
                        <x-filament::icon icon="heroicon-o-check-badge" class="h-5 w-5" />
                    </span>
                    <div>
                        <div class="text-sm font-bold text-gray-950 dark:text-white">Trabajo enfocado</div>
                        <p class="mt-1 text-xs pd-muted">Cada asignación muestra versión, checklist y fecha límite sin ruido operativo.</p>
                    </div>
                </div>
            </aside>
        </section>

        <section class="pd-dashboard-card">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="pd-title">Mi trabajo y honorarios</div>
                    <p class="mt-1 text-sm pd-muted">Resumen de tu actividad actual como revisor.</p>
                </div>
            </div>

            <div class="mt-4 grid gap-3 sm:grid-cols-2 xl:grid-cols-4">
                <div class="pd-card-soft p-4">
                    <div class="text-xs font-semibold pd-muted">Carga activa</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $reviewerMetrics['active_units'] }} U</div>
                </div>
                <div class="pd-card-soft p-4">
                    <div class="text-xs font-semibold pd-muted">Revisiones completadas</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $reviewerMetrics['completed_assignments'] }}</div>
                </div>
                <div class="pd-card-soft p-4">
                    <div class="text-xs font-semibold pd-muted">Trabajos por liquidar</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $reviewerMetrics['payable_items'] }}</div>
                </div>
                <div class="pd-card-soft p-4">
                    <div class="text-xs font-semibold pd-muted">Trabajos pagados</div>
                    <div class="mt-1 text-2xl font-extrabold text-gray-950 dark:text-white">{{ $reviewerMetrics['paid_items'] }}</div>
                </div>
            </div>

            @if ($reviewerMetrics['amounts'])
                <div class="mt-4 grid gap-2 md:grid-cols-2">
                    @foreach ($reviewerMetrics['amounts'] as $currency => $amount)
                        <div class="pd-card-soft flex flex-wrap items-center justify-between gap-2 p-3 text-sm">
                            <strong class="text-gray-950 dark:text-white">{{ $currency }}</strong>
                            <span class="pd-muted">Por liquidar {{ number_format($amount['approved_minor'] / 100, 2) }} · Pagado {{ number_format($amount['paid_minor'] / 100, 2) }}</span>
                        </div>
                    @endforeach
                </div>
            @endif

            <p class="mt-4 text-xs pd-muted">Las correcciones internas del mismo ciclo no generan un segundo honorario automático.</p>
        </section>

        <section class="pd-dashboard-card">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <div class="pd-title">Tu cuenta</div>
                    <p class="mt-1 text-sm pd-muted">Puedes actualizar tu nombre, correo y contraseña desde tu perfil.</p>
                </div>
                <x-filament::button tag="a" color="gray" icon="heroicon-o-user" href="{{ filament()->getProfileUrl() }}">Ver mi perfil</x-filament::button>
            </div>
        </section>
    </div>
</x-filament-panels::page>
