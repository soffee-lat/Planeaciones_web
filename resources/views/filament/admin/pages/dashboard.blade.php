<x-filament-panels::page>
    <div class="pd-dashboard">
        <section class="pd-dashboard-hero">
            <div>
                <div class="pd-eyebrow">Administración</div>
                <h2 class="pd-dashboard-hero__title">Hola, {{ auth()->user()->name }}</h2>
                <p class="pd-dashboard-hero__copy">
                    Gestiona el catálogo, solicitudes, planes, pagos y configuración operativa desde un mismo panel con una vista consistente.
                </p>
            </div>

            <aside class="pd-dashboard-hero__aside">
                <div class="flex items-center gap-3">
                    <span class="pd-icon-tile">
                        <x-filament::icon icon="heroicon-o-adjustments-horizontal" class="h-5 w-5" />
                    </span>
                    <div>
                        <div class="text-sm font-bold text-gray-950 dark:text-white">Panel operativo</div>
                        <p class="mt-1 text-xs pd-muted">Los recursos del menú lateral concentran la operación y supervisión del sistema.</p>
                    </div>
                </div>
            </aside>
        </section>

        <div class="pd-dashboard-grid">
            <section class="pd-dashboard-card">
                <div class="flex items-start gap-3">
                    <span class="pd-icon-tile">
                        <x-filament::icon icon="heroicon-o-circle-stack" class="h-5 w-5" />
                    </span>
                    <div>
                        <div class="pd-title">Operación centralizada</div>
                        <p class="mt-1 text-sm pd-muted">Currículo, solicitudes, planes, suscripciones, pagos y prompts comparten ahora la misma jerarquía visual.</p>
                    </div>
                </div>
            </section>

            <section class="pd-dashboard-card">
                <div class="flex items-start gap-3">
                    <span class="pd-icon-tile">
                        <x-filament::icon icon="heroicon-o-user-circle" class="h-5 w-5" />
                    </span>
                    <div class="min-w-0">
                        <div class="pd-title">Tu cuenta</div>
                        <p class="mt-1 text-sm pd-muted">Actualiza nombre, correo y contraseña desde tu perfil.</p>
                        <div class="mt-4">
                            <x-filament::button tag="a" color="gray" icon="heroicon-o-user" href="{{ filament()->getProfileUrl() }}">Ver mi perfil</x-filament::button>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
