<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="dark">
    <meta name="theme-color" content="#08111f">
    <title>Planeaciones Soffee | Planeación docente</title>
    <meta name="description" content="Organiza tus periodos, revisa conexiones curriculares y prepara tus planeaciones docentes en un solo lugar.">
    @vite('resources/css/landing.css')
</head>
<body>
    <div class="landing-shell">
        <header class="landing-header">
            <a class="landing-brand" href="{{ url('/app') }}" aria-label="Planeaciones Soffee, inicio">
                <span class="landing-brand__mark" aria-hidden="true">S</span>
                <span class="landing-brand__copy">
                    <strong>Planeaciones</strong>
                    <span>Soffee</span>
                </span>
            </a>

            <nav class="landing-nav" aria-label="Navegación principal">
                <a class="landing-nav__link is-active" href="{{ url('/app') }}">Inicio</a>
                <a class="landing-nav__link" href="#como-funciona">Cómo funciona</a>

                @auth
                    <a class="landing-button landing-button--small" href="{{ \App\Filament\App\Pages\Dashboard::getUrl() }}">
                        Mi espacio
                    </a>
                @else
                    <a class="landing-nav__link" href="{{ route('filament.app.auth.login') }}">Iniciar sesión</a>
                    <a class="landing-button landing-button--small" href="{{ route('filament.app.auth.register') }}">Registrarse</a>
                @endauth
            </nav>
        </header>

        <main>
            <section class="landing-hero">
                <div class="landing-hero__content">
                    <div class="landing-kicker">
                        <span class="landing-kicker__dot" aria-hidden="true"></span>
                        Planeación docente, más clara y organizada
                    </div>

                    <h1>
                        Tu planeación empieza con tus temas,
                        <span>no con una hoja en blanco.</span>
                    </h1>

                    <p class="landing-hero__lead">
                        Planeaciones Soffee te ayuda a organizar el periodo de trabajo, revisar las conexiones curriculares
                        y preparar una planeación estructurada para tu grupo.
                    </p>

                    <div class="landing-hero__actions">
                        @auth
                            <a class="landing-button" href="{{ \App\Filament\App\Pages\Dashboard::getUrl() }}">
                                Ir a mi espacio
                                <span aria-hidden="true">→</span>
                            </a>
                            <a class="landing-button landing-button--ghost" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">
                                Nueva planeación
                            </a>
                        @else
                            <a class="landing-button" href="{{ route('filament.app.auth.register') }}">
                                Crear una cuenta
                                <span aria-hidden="true">→</span>
                            </a>
                            <a class="landing-button landing-button--ghost" href="{{ route('filament.app.auth.login') }}">
                                Iniciar sesión
                            </a>
                        @endauth
                    </div>

                    <div class="landing-trust">
                        <span>✓</span> Diseñado para docentes
                        <span>✓</span> Flujo curricular guiado
                        <span>✓</span> Salida DOCX y PDF
                    </div>
                </div>

                <div class="landing-hero__visual" aria-label="Vista conceptual del flujo de una planeación">
                    <div class="landing-preview">
                        <div class="landing-preview__topbar">
                            <div>
                                <span class="landing-preview__eyebrow">PLANEACIÓN SEMANAL</span>
                                <strong>Conociendo mi entorno</strong>
                            </div>
                            <span class="landing-preview__status">Lista</span>
                        </div>

                        <div class="landing-preview__progress" aria-hidden="true">
                            <span class="is-done"></span>
                            <span class="is-done"></span>
                            <span class="is-active"></span>
                            <span></span>
                        </div>

                        <div class="landing-preview__grid">
                            <article>
                                <span class="landing-preview__icon">01</span>
                                <div>
                                    <small>Periodo</small>
                                    <strong>21–25 sep</strong>
                                </div>
                            </article>
                            <article>
                                <span class="landing-preview__icon">02</span>
                                <div>
                                    <small>Grupo</small>
                                    <strong>6° A</strong>
                                </div>
                            </article>
                            <article class="is-wide">
                                <span class="landing-preview__icon">03</span>
                                <div>
                                    <small>Conexiones curriculares</small>
                                    <strong>Lenguajes · Saberes · Ética · De lo Humano</strong>
                                </div>
                            </article>
                        </div>

                        <div class="landing-preview__sessions">
                            <div>
                                <span>LUN</span>
                                <p><strong>Lenguajes</strong><small>Lectura y organización de información</small></p>
                                <b>50 min</b>
                            </div>
                            <div>
                                <span>MAR</span>
                                <p><strong>Saberes</strong><small>Cuidado de la salud y prevención</small></p>
                                <b>50 min</b>
                            </div>
                            <div>
                                <span>MIÉ</span>
                                <p><strong>De lo Humano</strong><small>Convivencia y participación</small></p>
                                <b>50 min</b>
                            </div>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-section" id="como-funciona">
                <div class="landing-section__heading">
                    <span class="landing-eyebrow">Cómo funciona</span>
                    <h2>De tu contexto a una planeación lista para trabajar.</h2>
                    <p>El sistema organiza el proceso en pasos claros para que puedas revisar lo importante antes de generar el documento final.</p>
                </div>

                <div class="landing-feature-grid">
                    <article class="landing-feature">
                        <span class="landing-feature__number">01</span>
                        <h3>Define tu periodo y tus temas</h3>
                        <p>Selecciona el grupo, semana o mes, materias y los temas que vas a trabajar.</p>
                    </article>
                    <article class="landing-feature">
                        <span class="landing-feature__number">02</span>
                        <h3>Revisa las conexiones curriculares</h3>
                        <p>Confirma contenidos y PDA relacionados con lo que realmente quieres trabajar con tu grupo.</p>
                    </article>
                    <article class="landing-feature">
                        <span class="landing-feature__number">03</span>
                        <h3>Obtén tu documento</h3>
                        <p>Una vez aprobado el contenido, aplica el formato de salida y prepara tus archivos DOCX y PDF.</p>
                    </article>
                </div>
            </section>

            <section class="landing-cta">
                <div>
                    <span class="landing-eyebrow">Planeaciones Soffee</span>
                    <h2>Organiza tu próxima planeación desde un solo lugar.</h2>
                </div>
                <div class="landing-cta__actions">
                    @auth
                        <a class="landing-button" href="{{ \App\Filament\App\Pages\Dashboard::getUrl() }}">Entrar a mi espacio</a>
                    @else
                        <a class="landing-button" href="{{ route('filament.app.auth.register') }}">Registrarme</a>
                        <a class="landing-button landing-button--ghost" href="{{ route('filament.app.auth.login') }}">Iniciar sesión</a>
                    @endauth
                </div>
            </section>
        </main>

        <footer class="landing-footer">
            <div class="landing-brand landing-brand--footer">
                <span class="landing-brand__mark" aria-hidden="true">S</span>
                <span class="landing-brand__copy">
                    <strong>Planeaciones</strong>
                    <span>Soffee</span>
                </span>
            </div>
            <p>© {{ now()->year }} Soffee. Herramientas digitales para organizar mejor tu trabajo docente.</p>
        </footer>
    </div>
</body>
</html>
