<x-filament-panels::page>
    @php
        $user = auth()->user();
        $accountReady = (bool) $user->onboarding_completed_at;
        $profileReady = $user->hasPedagogicalOnboardingComplete();
        $planningReady = $eligibleGroupCount > 0;
        $ready = $accountReady && $profileReady && $planningReady;
    @endphp

    <div class="pd-dashboard">
        <section class="pd-dashboard-hero">
            <div>
                <div class="pd-eyebrow">Tu espacio docente</div>
                <h1 class="pd-dashboard-hero__title">Hola, {{ $user->name }}</h1>
                <p class="pd-dashboard-hero__copy">
                    Organiza tus grupos y crea planeaciones sin repetir información.
                    Nosotros te iremos indicando qué falta y cuál es el siguiente paso.
                </p>

                <div class="pd-dashboard-hero__actions">
                    @if ($ready)
                        <x-filament::button
                            tag="a"
                            size="lg"
                            icon="heroicon-o-document-plus"
                            href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}"
                        >
                            Crear nueva planeación
                        </x-filament::button>
                    @elseif (! $accountReady)
                        <x-filament::button
                            tag="a"
                            size="lg"
                            icon="heroicon-o-user"
                            href="{{ \App\Filament\App\Pages\Onboarding::getUrl() }}"
                        >
                            Completar mi cuenta
                        </x-filament::button>
                    @else
                        <x-filament::button
                            tag="a"
                            size="lg"
                            icon="heroicon-o-user-group"
                            href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}"
                        >
                            Preparar mis grupos
                        </x-filament::button>
                    @endif

                    <x-filament::button
                        tag="a"
                        color="gray"
                        icon="heroicon-o-document-text"
                        href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}"
                    >
                        Mis planeaciones
                    </x-filament::button>
                </div>
            </div>

            <div class="pd-dashboard-hero__aside">
                <div class="pd-dashboard-ready">
                    <span class="pd-dashboard-ready__icon {{ $ready ? 'is-ready' : '' }}">
                        <x-filament::icon :icon="$ready ? 'heroicon-o-check' : 'heroicon-o-sparkles'" class="h-5 w-5" />
                    </span>
                    <div>
                        <div class="pd-dashboard-ready__label">{{ $ready ? 'Listo para planear' : 'Te ayudamos a dejarlo listo' }}</div>
                        <div class="pd-dashboard-ready__copy">
                            @if ($ready)
                                Tienes {{ $eligibleGroupCount }} {{ $eligibleGroupCount === 1 ? 'grupo preparado' : 'grupos preparados' }}.
                            @elseif (! $accountReady)
                                Primero necesitamos tus datos básicos.
                            @elseif (! $profileReady)
                                Falta completar la información docente.
                            @else
                                Falta dejar al menos un grupo con horario listo.
                            @endif
                        </div>
                    </div>
                </div>

                <div class="pd-dashboard-mini-stats">
                    <div>
                        <strong>{{ $planningCount }}</strong>
                        <span>Planeaciones</span>
                    </div>
                    <div>
                        <strong>{{ $groupCount }}</strong>
                        <span>Grupos</span>
                    </div>
                    <div>
                        <strong>{{ $schoolCount }}</strong>
                        <span>Escuelas</span>
                    </div>
                </div>
            </div>
        </section>

        @if (! $ready)
            <section class="pd-guidance-card">
                <div class="pd-guidance-card__icon">
                    <x-filament::icon icon="heroicon-o-light-bulb" class="h-5 w-5" />
                </div>
                <div class="pd-guidance-card__body">
                    @if (! $accountReady)
                        <strong>Primero completa tu cuenta.</strong>
                        <span>Es información que capturas una sola vez y después reutilizamos automáticamente.</span>
                        <a href="{{ \App\Filament\App\Pages\Onboarding::getUrl() }}">Completar ahora →</a>
                    @elseif (! $profileReady)
                        <strong>Falta preparar tu información docente.</strong>
                        <span>Registra tu escuela y completa el perfil de al menos un grupo.</span>
                        <a href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Revisar mis grupos →</a>
                    @else
                        <strong>Tu perfil está completo; sólo falta preparar un grupo.</strong>
                        <span>Normalmente sólo falta configurar el horario o incluir las materias que deben aparecer en la planeación.</span>
                        <a href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">Ver qué me falta →</a>
                    @endif
                </div>
            </section>
        @endif

        <section>
            <div class="pd-dashboard-section-head">
                <div>
                    <div class="pd-title">Accesos rápidos</div>
                    <div class="pd-muted">Las tareas que más usarás están aquí, sin buscar entre menús.</div>
                </div>
            </div>

            <div class="pd-dashboard-quick-grid">
                <a class="pd-dashboard-link-card pd-dashboard-link-card--primary" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">
                    <span class="pd-dashboard-link-card__icon"><x-filament::icon icon="heroicon-o-document-plus" class="h-6 w-6" /></span>
                    <span>
                        <strong>Nueva planeación</strong>
                        <small>Elige grupo, periodo y temas. Te guiamos paso a paso.</small>
                    </span>
                    <span class="pd-dashboard-link-card__arrow">→</span>
                </a>

                <a class="pd-dashboard-link-card" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">
                    <span class="pd-dashboard-link-card__icon"><x-filament::icon icon="heroicon-o-document-text" class="h-6 w-6" /></span>
                    <span>
                        <strong>Mis planeaciones</strong>
                        <small>Consulta avances, descargas y solicitudes anteriores.</small>
                    </span>
                    <span class="pd-dashboard-link-card__arrow">→</span>
                </a>

                <a class="pd-dashboard-link-card" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">
                    <span class="pd-dashboard-link-card__icon"><x-filament::icon icon="heroicon-o-user-group" class="h-6 w-6" /></span>
                    <span>
                        <strong>Mis grupos</strong>
                        <small>Perfil, grado, horario y estado para planear.</small>
                    </span>
                    <span class="pd-dashboard-link-card__arrow">→</span>
                </a>

                <a class="pd-dashboard-link-card" href="{{ \App\Filament\Resources\InstitutionalFormats\InstitutionalFormatResource::getUrl() }}">
                    <span class="pd-dashboard-link-card__icon"><x-filament::icon icon="heroicon-o-document" class="h-6 w-6" /></span>
                    <span>
                        <strong>Mis formatos</strong>
                        <small>Administra el formato institucional de tus documentos.</small>
                    </span>
                    <span class="pd-dashboard-link-card__arrow">→</span>
                </a>
            </div>
        </section>

        <div class="pd-dashboard-grid">
            <section class="pd-dashboard-card">
                <div class="pd-dashboard-section-head">
                    <div>
                        <div class="pd-title">Planeaciones recientes</div>
                        <div class="pd-muted">Retoma rápidamente lo último que estabas trabajando.</div>
                    </div>
                    <a class="pd-text-link" href="{{ \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl() }}">Ver todas</a>
                </div>

                @if ($recentPlanning === [])
                    <div class="pd-empty-state">
                        <span class="pd-empty-state__icon"><x-filament::icon icon="heroicon-o-document-plus" class="h-6 w-6" /></span>
                        <strong>Aún no tienes planeaciones.</strong>
                        <span>Cuando crees la primera, aparecerá aquí para que puedas retomarla fácilmente.</span>
                    </div>
                @else
                    <div class="pd-recent-list">
                        @foreach ($recentPlanning as $planning)
                            <a class="pd-recent-item" href="{{ $planning['url'] }}">
                                <span class="pd-recent-item__icon"><x-filament::icon icon="heroicon-o-document-text" class="h-5 w-5" /></span>
                                <span class="pd-recent-item__main">
                                    <strong>{{ $planning['title'] }}</strong>
                                    <small>{{ $planning['group'] }} · {{ $planning['updated'] }}</small>
                                </span>
                                <span class="pd-recent-item__status">{{ $planning['status'] }}</span>
                            </a>
                        @endforeach
                    </div>
                @endif
            </section>

            <section class="pd-dashboard-card">
                <div class="pd-dashboard-section-head">
                    <div>
                        <div class="pd-title">Tu espacio está preparado así</div>
                        <div class="pd-muted">Puedes ajustar estos datos cuando cambie tu contexto escolar.</div>
                    </div>
                </div>

                <div class="pd-readiness-list">
                    <a class="pd-readiness-row" href="{{ \App\Filament\App\Resources\Schools\SchoolResource::getUrl() }}">
                        <span class="pd-step-dot">{{ $schoolCount > 0 ? '✓' : '1' }}</span>
                        <span><strong>Escuela</strong><small>{{ $schoolCount > 0 ? 'Registrada' : 'Falta registrar una escuela' }}</small></span>
                    </a>
                    <a class="pd-readiness-row" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">
                        <span class="pd-step-dot">{{ $profileReady ? '✓' : '2' }}</span>
                        <span><strong>Perfil del grupo</strong><small>{{ $profileReady ? 'Información docente completa' : 'Falta completar un grupo' }}</small></span>
                    </a>
                    <a class="pd-readiness-row" href="{{ \App\Filament\App\Resources\Groups\GroupResource::getUrl() }}">
                        <span class="pd-step-dot">{{ $planningReady ? '✓' : '3' }}</span>
                        <span><strong>Horario para planear</strong><small>{{ $planningReady ? $eligibleGroupCount.' grupo(s) listo(s)' : 'Falta un horario utilizable' }}</small></span>
                    </a>
                    <a class="pd-readiness-row" href="{{ \App\Filament\App\Pages\MyPlan::getUrl() }}">
                        <span class="pd-step-dot">{{ ($planSummary['has_plan'] ?? false) ? '✓' : '4' }}</span>
                        <span><strong>Mi plan</strong><small>{{ ($planSummary['has_plan'] ?? false) ? ($planSummary['plan_name'] ?? 'Plan activo') : 'Sin plan activo' }}</small></span>
                    </a>
                </div>

                <div class="pd-account-link">
                    <a href="{{ filament()->getProfileUrl() }}">
                        <x-filament::icon icon="heroicon-o-user-circle" class="h-5 w-5" />
                        Administrar mi perfil
                    </a>
                </div>
            </section>
        </div>
    </div>
</x-filament-panels::page>
