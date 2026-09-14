<x-filament-panels::page>
    <div class="pd-dashboard">
        <section class="pd-dashboard-hero">
            <div>
                <div class="pd-eyebrow">Tu espacio de planeación</div>
                <h2 class="pd-dashboard-hero__title">Hola, {{ auth()->user()->name }}</h2>

                @if (! auth()->user()->onboarding_completed_at)
                    <p class="pd-dashboard-hero__copy">
                        Termina la configuración básica de tu cuenta para comenzar a crear planeaciones con una experiencia más personalizada.
                    </p>
                    <div class="pd-dashboard-hero__actions">
                        <x-filament::button tag="a" size="lg" icon="heroicon-o-arrow-right" icon-position="after" href="{{ \App\Filament\App\Pages\Onboarding::getUrl() }}">
                            Completar mi cuenta
                        </x-filament::button>
                    </div>
                @elseif (! auth()->user()->hasPedagogicalOnboardingComplete())
                    <p class="pd-dashboard-hero__copy">
                        Tu cuenta está lista. Sólo falta agregar tu escuela, tu grupo y su perfil pedagógico para adaptar mejor las planeaciones a tu contexto real.
                    </p>
                    <div class="pd-dashboard-hero__actions">
                        <x-filament::button tag="a" icon="heroicon-o-building-office-2" href="{{ \App\Filament\App\Resources\Schools\SchoolResource::getUrl() }}">Mis escuelas</x-filament::button>
                        <x-filament::button tag="a" color="gray" icon="heroicon-o-user-group" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Mis grupos</x-filament::button>
                    </div>
                @else
                    <p class="pd-dashboard-hero__copy">
                        Crea una planeación con tus datos esenciales, revisa la propuesta pedagógica y descarga el resultado final en PDF o DOCX.
                    </p>
                    <div class="pd-dashboard-hero__actions">
                        <x-filament::button tag="a" size="lg" icon="heroicon-o-document-plus" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">
                            Nueva planeación
                        </x-filament::button>
                        <x-filament::button tag="a" size="lg" color="gray" icon="heroicon-o-folder-open" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">
                            Ver mis planeaciones
                        </x-filament::button>
                    </div>
                @endif
            </div>

            <aside class="pd-dashboard-hero__aside">
                <div class="flex items-center gap-3">
                    <span class="pd-icon-tile">
                        <x-filament::icon icon="heroicon-o-sparkles" class="h-5 w-5" />
                    </span>
                    <div>
                        <div class="text-sm font-bold text-gray-950 dark:text-white">Flujo simple</div>
                        <p class="mt-1 text-xs pd-muted">De la idea al documento final sin llenar formularios innecesarios.</p>
                    </div>
                </div>
                <div class="flex flex-wrap gap-2">
                    <span class="pd-chip">Datos esenciales</span>
                    <span class="pd-chip">Currículo</span>
                    <span class="pd-chip">Revisión</span>
                    <span class="pd-chip">PDF / DOCX</span>
                </div>
            </aside>
        </section>

        <div class="pd-dashboard-grid">
            <section class="pd-dashboard-card">
                <div class="flex items-start gap-3">
                    <span class="pd-icon-tile">
                        <x-filament::icon icon="heroicon-o-academic-cap" class="h-5 w-5" />
                    </span>
                    <div>
                        <div class="pd-title">Tu contexto pedagógico</div>
                        <p class="mt-1 text-sm pd-muted">Mantén actualizados tu escuela, grupos y perfil para que las planeaciones reflejen mejor tu realidad en el aula.</p>
                    </div>
                </div>

                <div class="mt-4 flex flex-wrap gap-2">
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-building-office-2" href="{{ \App\Filament\App\Resources\Schools\SchoolResource::getUrl() }}">Escuelas</x-filament::button>
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-user-group" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Grupos</x-filament::button>
                    <x-filament::button tag="a" color="gray" icon="heroicon-o-user-circle" href="{{ filament()->getProfileUrl() }}">Mi perfil</x-filament::button>
                </div>
            </section>

            <section class="pd-dashboard-card">
                <div class="pd-title">Así funciona</div>
                <div class="mt-4">
                    <div class="pd-step-row">
                        <span class="pd-step-dot">1</span>
                        <div>
                            <div class="text-sm font-bold text-gray-950 dark:text-white">Describe lo esencial</div>
                            <p class="mt-1 text-xs pd-muted">Grupo, fechas, tema e indicaciones importantes.</p>
                        </div>
                    </div>
                    <div class="pd-step-row">
                        <span class="pd-step-dot">2</span>
                        <div>
                            <div class="text-sm font-bold text-gray-950 dark:text-white">Revisa la propuesta</div>
                            <p class="mt-1 text-xs pd-muted">Currículo, sesiones, actividades y evaluación en una vista fácil de interpretar.</p>
                        </div>
                    </div>
                    <div class="pd-step-row">
                        <span class="pd-step-dot">3</span>
                        <div>
                            <div class="text-sm font-bold text-gray-950 dark:text-white">Descarga y utiliza</div>
                            <p class="mt-1 text-xs pd-muted">Elige el formato al final y recibe tu documento en PDF y DOCX.</p>
                        </div>
                    </div>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
