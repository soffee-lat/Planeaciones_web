@php
    $panelId = filament()->getCurrentPanel()?->getId() ?? 'app';
    $routeName = (string) (request()->route()?->getName() ?? '');
    $isAuth = str_contains($routeName, '.auth.');
    $isAdmin = $panelId === 'admin';
    $isReview = $panelId === 'review';
    $isRegister = str_contains($routeName, '.register');
    $isPassword = str_contains($routeName, 'password-reset');

    if ($isAdmin) {
        $eyebrow = 'SOFFEE · ADMINISTRACIÓN';
        $title = 'Centro de control de Planeaciones Soffee.';
        $copy = 'Gestiona usuarios, operación, catálogos y planes desde un acceso separado del espacio docente.';
    } elseif ($isReview) {
        $eyebrow = 'SOFFEE · REVISIÓN';
        $title = 'Revisión pedagógica, en un espacio separado.';
        $copy = 'Consulta asignaciones, revisa documentos y da seguimiento a las planeaciones que requieren intervención humana.';
    } elseif ($isRegister) {
        $eyebrow = 'EMPIEZA CON SOFFEE';
        $title = 'Prepara tu espacio docente una sola vez.';
        $copy = 'Crea tu cuenta, registra tu grupo y deja listo el contexto que reutilizarás en tus próximas planeaciones.';
    } elseif ($isPassword) {
        $eyebrow = 'RECUPERA TU ACCESO';
        $title = 'Vuelve a tu trabajo sin empezar de cero.';
        $copy = 'Recupera el acceso a tus grupos, planeaciones y documentos desde el mismo espacio docente.';
    } else {
        $eyebrow = 'PLANEACIONES SOFFEE';
        $title = 'Tu planeación continúa aquí.';
        $copy = 'Entra a tu espacio docente y retoma grupos, periodos y documentos justo donde los dejaste.';
    }
@endphp

@if ($isAuth)
    <div class="pd-auth-scene pd-auth-scene--{{ $isAdmin ? 'admin' : ($isReview ? 'review' : 'app') }}" aria-hidden="true">
        <div class="pd-auth-scene__mesh"></div>
        <div class="pd-auth-scene__orb pd-auth-scene__orb--one"></div>
        <div class="pd-auth-scene__orb pd-auth-scene__orb--two"></div>

        <div class="pd-auth-scene__content">
            <a class="pd-auth-scene__brand" href="{{ $isAdmin ? url('/admin') : url('/app') }}" tabindex="-1">
                <img
                    src="{{ asset($isAdmin ? 'branding/soffee-logo-white.webp' : 'branding/soffee-logo-white.webp') }}"
                    alt="Soffee"
                >
                <span>{{ $isAdmin ? 'Administración' : ($isReview ? 'Revisión' : 'Planeaciones') }}</span>
            </a>

            <div class="pd-auth-scene__eyebrow">{{ $eyebrow }}</div>
            <h1>{{ $title }}</h1>
            <p>{{ $copy }}</p>

            @if ($isAdmin)
                <div class="pd-auth-admin-grid">
                    <div><strong>Usuarios</strong><span>Cuentas y acceso</span></div>
                    <div><strong>Operación IA</strong><span>Ejecuciones y revisión</span></div>
                    <div><strong>Catálogo</strong><span>Currículo y formatos</span></div>
                    <div><strong>Planes</strong><span>Suscripciones y uso</span></div>
                </div>

                <div class="pd-auth-admin-note">
                    <span>🔒</span>
                    <div>
                        <strong>Acceso interno</strong>
                        <small>Este panel es independiente del espacio de clientes.</small>
                    </div>
                </div>
            @elseif ($isReview)
                <div class="pd-auth-admin-grid pd-auth-review-grid">
                    <div><strong>Asignaciones</strong><span>Trabajo pendiente</span></div>
                    <div><strong>Revisión</strong><span>Contenido y calidad</span></div>
                    <div><strong>Correcciones</strong><span>Seguimiento claro</span></div>
                    <div><strong>Historial</strong><span>Trazabilidad del proceso</span></div>
                </div>

                <div class="pd-auth-admin-note pd-auth-review-note">
                    <span>◎</span>
                    <div>
                        <strong>Espacio de revisión</strong>
                        <small>Separado del acceso de clientes y administración.</small>
                    </div>
                </div>
            @else
                <div class="pd-auth-benefits">
                    <div>
                        <span>✓</span>
                        <p><strong>Tu contexto permanece listo</strong><small>Escuela, grupo y horario se reutilizan.</small></p>
                    </div>
                    <div>
                        <span>✓</span>
                        <p><strong>Sigue donde te quedaste</strong><small>Retoma planeaciones y revisiones en curso.</small></p>
                    </div>
                    <div>
                        <span>✓</span>
                        <p><strong>Un flujo claro</strong><small>Sabes qué falta y cuál es el siguiente paso.</small></p>
                    </div>
                </div>

                <div class="pd-auth-mini-preview">
                    <div class="pd-auth-mini-preview__head">
                        <span class="pd-auth-mini-preview__mark">
                            <img src="{{ asset('branding/soffee-icon.webp') }}" alt="">
                        </span>
                        <p><small>Tu espacio docente</small><strong>Listo para planear</strong></p>
                        <b>✓</b>
                    </div>
                    <div class="pd-auth-mini-preview__steps">
                        <span class="is-done">1</span><i></i>
                        <span class="is-done">2</span><i></i>
                        <span class="is-done">3</span><i></i>
                        <span class="is-current">4</span>
                    </div>
                    <div class="pd-auth-mini-preview__labels">
                        <span>Escuela</span><span>Grupo</span><span>Horario</span><span>Planear</span>
                    </div>
                </div>
            @endif
        </div>

        <div class="pd-auth-scene__footer">
            <span>© {{ now()->year }} Soffee</span>
            <span>{{ $isAdmin ? 'Panel interno' : ($isReview ? 'Revisión pedagógica' : 'Preescolar y primaria · DOCX y PDF') }}</span>
        </div>
    </div>

    <a class="pd-auth-back-link" href="{{ $isAdmin ? url('/app') : url('/app') }}">
        <span aria-hidden="true">←</span>
        {{ $isAdmin ? 'Volver al sitio' : 'Volver al inicio' }}
    </a>
@endif
