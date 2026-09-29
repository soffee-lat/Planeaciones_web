<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <meta name="theme-color" content="#ffffff">
    <title>Planeaciones Soffee | Planeación docente</title>
    <meta name="description" content="Organiza tus periodos, revisa conexiones curriculares y prepara tus planeaciones docentes en un solo lugar.">
    <link rel="icon" type="image/webp" href="{{ asset('branding/soffee-icon.webp') }}">
    @vite(['resources/css/landing.css', 'resources/js/landing.js'])
</head>
<body>
    <div class="landing-page">
        <header class="landing-header" data-landing-header>
            <div class="landing-header__inner">
                <a class="landing-brand" href="{{ url('/app') }}" aria-label="Planeaciones Soffee, inicio">
                    <span class="landing-brand__mark">
                        <img src="{{ asset('branding/soffee-icon.webp') }}" alt="" aria-hidden="true">
                    </span>
                    <span class="landing-brand__wordmark">
                        <img src="{{ asset('branding/soffee-logo-white.webp') }}" alt="Soffee">
                    </span>
                    <span class="landing-brand__divider" aria-hidden="true"></span>
                    <span class="landing-brand__product">Planeaciones</span>
                </a>

                <button class="landing-menu-button" type="button" aria-label="Abrir menú" aria-expanded="false" data-menu-toggle>
                    <span></span><span></span><span></span>
                </button>

                <nav class="landing-nav" aria-label="Navegación principal" data-menu>
                    <a class="landing-nav__link is-active" href="#inicio" data-nav-link>Inicio</a>
                    <a class="landing-nav__link" href="#como-funciona" data-nav-link>Cómo funciona</a>
                    <a class="landing-nav__link" href="#para-docentes" data-nav-link>Para docentes</a>
                    @auth
                        <a class="landing-button landing-button--small landing-button--ghost" href="{{ \App\Filament\App\Pages\Dashboard::getUrl() }}">Mi espacio</a>
                        <a class="landing-button landing-button--small" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">Nueva planeación</a>
                    @else
                        <a class="landing-nav__link" href="{{ route('filament.app.auth.login') }}">Iniciar sesión</a>
                        <a class="landing-button landing-button--small" href="{{ route('filament.app.auth.register') }}">Registrarse</a>
                    @endauth
                </nav>
            </div>
        </header>

        <main>
            <section class="landing-hero landing-shell" id="inicio">
                <div class="landing-hero__content" data-reveal>
                    <div class="landing-kicker">
                        <span class="landing-kicker__dot" aria-hidden="true"></span>
                        Planeación docente guiada
                    </div>

                    <h1>
                        Planea desde tu grupo,
                        <span>no desde una hoja en blanco.</span>
                    </h1>

                    <p class="landing-hero__lead">
                        Organiza el periodo, tus temas y el horario del grupo. Planeaciones Soffee te guía para revisar
                        las conexiones curriculares y llegar a un documento listo para trabajar.
                    </p>

                    <div class="landing-hero__actions">
                        @auth
                            <a class="landing-button" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">
                                Crear nueva planeación
                                <span aria-hidden="true">→</span>
                            </a>
                            <a class="landing-button landing-button--ghost" href="{{ \App\Filament\App\Pages\Dashboard::getUrl() }}">Ir a mi espacio</a>
                        @else
                            <a class="landing-button" href="{{ route('filament.app.auth.register') }}">
                                Crear una cuenta
                                <span aria-hidden="true">→</span>
                            </a>
                            <a class="landing-button landing-button--ghost" href="{{ route('filament.app.auth.login') }}">Iniciar sesión</a>
                        @endauth
                    </div>

                    <div class="landing-trust">
                        <span><b>✓</b> Preescolar y primaria</span>
                        <span><b>✓</b> Flujo curricular guiado</span>
                        <span><b>✓</b> DOCX y PDF</span>
                    </div>
                </div>

                <div class="landing-hero__visual" data-reveal>
                    <div class="landing-demo" data-demo>
                        <div class="landing-demo__top">
                            <div class="landing-demo__brand">
                                <span class="landing-demo__logo"><img src="{{ asset('branding/soffee-icon.webp') }}" alt="" aria-hidden="true"></span>
                                <div>
                                    <span>PLANEACIÓN SEMANAL</span>
                                    <strong data-demo-title>Conociendo mi entorno</strong>
                                </div>
                            </div>
                            <span class="landing-demo__status" data-demo-status>En preparación</span>
                        </div>

                        <div class="landing-demo__tabs" role="tablist" aria-label="Vista de ejemplo">
                            <button class="is-active" type="button" role="tab" aria-selected="true" data-demo-tab="periodo">Periodo</button>
                            <button type="button" role="tab" aria-selected="false" data-demo-tab="curriculo">Currículo</button>
                            <button type="button" role="tab" aria-selected="false" data-demo-tab="documento">Documento</button>
                        </div>

                        <div class="landing-demo__panel is-active" data-demo-panel="periodo">
                            <div class="landing-demo__summary">
                                <article><span>01</span><small>Periodo</small><strong>21–25 sep</strong></article>
                                <article><span>02</span><small>Grupo</small><strong>2° B</strong></article>
                                <article><span>03</span><small>Nivel</small><strong>Primaria</strong></article>
                            </div>
                            <div class="landing-demo__schedule">
                                <div><b>LUN</b><span><strong>Lenguajes</strong><small>Lectura y organización</small></span><em>50 min</em></div>
                                <div><b>MAR</b><span><strong>Saberes</strong><small>Cuidado de la salud</small></span><em>50 min</em></div>
                                <div><b>MIÉ</b><span><strong>De lo Humano</strong><small>Convivencia y participación</small></span><em>50 min</em></div>
                            </div>
                        </div>

                        <div class="landing-demo__panel" data-demo-panel="curriculo">
                            <div class="landing-demo__notice">
                                <span>✓</span>
                                <div><strong>Conexiones encontradas</strong><small>Revisa contenidos y PDA antes de confirmar.</small></div>
                            </div>
                            <div class="landing-demo__curriculum">
                                <article><span>LEN</span><div><strong>Lenguajes</strong><small>Contenido curricular relacionado</small></div><b>2 PDA</b></article>
                                <article><span>ENS</span><div><strong>Ética, Naturaleza y Sociedades</strong><small>Conexión con el tema semanal</small></div><b>1 PDA</b></article>
                                <article><span>DHC</span><div><strong>De lo Humano y Comunitario</strong><small>Convivencia y participación</small></div><b>2 PDA</b></article>
                            </div>
                        </div>

                        <div class="landing-demo__panel" data-demo-panel="documento">
                            <div class="landing-demo__document">
                                <div class="landing-demo__paper">
                                    <div class="landing-demo__paper-head">
                                        <img src="{{ asset('branding/soffee-icon.webp') }}" alt="" aria-hidden="true">
                                        <span><strong>Planeación semanal</strong><small>Grupo 2° B · 21–25 sep</small></span>
                                    </div>
                                    <div class="landing-demo__paper-line is-wide"></div>
                                    <div class="landing-demo__paper-line"></div>
                                    <div class="landing-demo__paper-line"></div>
                                    <div class="landing-demo__paper-grid"><span></span><span></span><span></span></div>
                                </div>
                                <div class="landing-demo__files">
                                    <span><b>DOCX</b><small>Editable</small></span>
                                    <span><b>PDF</b><small>Listo para compartir</small></span>
                                </div>
                            </div>
                        </div>

                        <div class="landing-demo__hint">
                            <span class="landing-demo__pulse" aria-hidden="true"></span>
                            Vista de ejemplo · cambia de pestaña
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-strip" aria-label="Características principales">
                <div class="landing-shell landing-strip__inner">
                    <div><strong>Preescolar</strong><span>Fase 2</span></div>
                    <i></i>
                    <div><strong>Primaria</strong><span>Fases 3–5</span></div>
                    <i></i>
                    <div><strong>Horario real</strong><span>La planeación parte de tu grupo</span></div>
                    <i></i>
                    <div><strong>Salida flexible</strong><span>DOCX y PDF</span></div>
                </div>
            </section>

            <section class="landing-section landing-shell" id="como-funciona">
                <div class="landing-section__heading" data-reveal>
                    <span class="landing-eyebrow">Cómo funciona</span>
                    <h2>Un proceso claro, con el siguiente paso siempre visible.</h2>
                    <p>Capturas lo necesario una vez y el sistema reutiliza el contexto de tu grupo para acompañarte durante la planeación.</p>
                </div>

                <div class="landing-steps">
                    <article class="landing-step" data-reveal>
                        <span class="landing-step__number">01</span>
                        <div class="landing-step__icon">○</div>
                        <h3>Elige periodo y temas</h3>
                        <p>Selecciona el grupo, la semana o el mes y define los temas que vas a trabajar.</p>
                        <div class="landing-step__tag">Tu contexto</div>
                    </article>
                    <article class="landing-step" data-reveal>
                        <span class="landing-step__number">02</span>
                        <div class="landing-step__icon">↗</div>
                        <h3>Revisa conexiones</h3>
                        <p>Confirma contenidos, PDA y campos relacionados con lo que realmente necesitas enseñar.</p>
                        <div class="landing-step__tag">Currículo guiado</div>
                    </article>
                    <article class="landing-step" data-reveal>
                        <span class="landing-step__number">03</span>
                        <div class="landing-step__icon">✓</div>
                        <h3>Confirma y genera</h3>
                        <p>Revisa el resumen y continúa al seguimiento hasta obtener el documento final.</p>
                        <div class="landing-step__tag">Documento final</div>
                    </article>
                </div>
            </section>

            <section class="landing-benefits" id="para-docentes">
                <div class="landing-shell landing-benefits__inner">
                    <div class="landing-benefits__copy" data-reveal>
                        <span class="landing-eyebrow">Pensado para docentes</span>
                        <h2>Menos configuración técnica. Más claridad para decidir.</h2>
                        <p>
                            El sistema está diseñado para decirte qué falta, dónde completarlo y qué sigue.
                            La información de escuela, grupo y horario se reutiliza para evitar capturas repetidas.
                        </p>

                        <div class="landing-checklist">
                            <div><span>✓</span><p><strong>Tu grupo queda preparado</strong><small>Perfil pedagógico, grado y horario se reutilizan.</small></p></div>
                            <div><span>✓</span><p><strong>Ves lo que falta</strong><small>Los pasos incompletos se muestran antes de continuar.</small></p></div>
                            <div><span>✓</span><p><strong>Revisas antes de confirmar</strong><small>El currículo y el resumen pasan por una revisión explícita.</small></p></div>
                        </div>
                    </div>

                    <div class="landing-benefits__visual" data-reveal>
                        <div class="landing-flow-card">
                            <div class="landing-flow-card__head">
                                <span class="landing-flow-card__avatar"><img src="{{ asset('branding/soffee-icon.webp') }}" alt="" aria-hidden="true"></span>
                                <div><small>Tu espacio docente</small><strong>Listo para planear</strong></div>
                                <b>✓</b>
                            </div>
                            <div class="landing-flow-card__progress">
                                <div class="is-done"><span>1</span><p><strong>Escuela</strong><small>Registrada</small></p></div>
                                <div class="is-done"><span>2</span><p><strong>Grupo</strong><small>Perfil completo</small></p></div>
                                <div class="is-done"><span>3</span><p><strong>Horario</strong><small>Listo para usar</small></p></div>
                                <div class="is-current"><span>4</span><p><strong>Nueva planeación</strong><small>Ya puedes comenzar</small></p></div>
                            </div>
                            <a href="{{ auth()->check() ? \App\Filament\App\Pages\StartPlanning::getUrl() : route('filament.app.auth.register') }}">
                                {{ auth()->check() ? 'Crear nueva planeación' : 'Preparar mi espacio' }} <span>→</span>
                            </a>
                        </div>
                    </div>
                </div>
            </section>

            <section class="landing-cta landing-shell" data-reveal>
                <div>
                    <span class="landing-eyebrow">Planeaciones Soffee</span>
                    <h2>Tu siguiente planeación puede empezar con más claridad.</h2>
                    <p>Prepara una vez el contexto de tu grupo y deja que el sistema te acompañe en el resto del flujo.</p>
                </div>
                <div class="landing-cta__actions">
                    @auth
                        <a class="landing-button" href="{{ \App\Filament\App\Pages\StartPlanning::getUrl() }}">Nueva planeación</a>
                        <a class="landing-button landing-button--ghost" href="{{ \App\Filament\App\Pages\Dashboard::getUrl() }}">Mi espacio</a>
                    @else
                        <a class="landing-button" href="{{ route('filament.app.auth.register') }}">Crear mi cuenta</a>
                        <a class="landing-button landing-button--ghost" href="{{ route('filament.app.auth.login') }}">Iniciar sesión</a>
                    @endauth
                </div>
            </section>
        </main>

        <footer class="landing-footer">
            <div class="landing-shell landing-footer__inner">
                <div class="landing-brand landing-brand--footer">
                    <span class="landing-brand__mark"><img src="{{ asset('branding/soffee-icon.webp') }}" alt="" aria-hidden="true"></span>
                    <span class="landing-brand__wordmark"><img src="{{ asset('branding/soffee-logo-white.webp') }}" alt="Soffee"></span>
                    <span class="landing-brand__divider" aria-hidden="true"></span>
                    <span class="landing-brand__product">Planeaciones</span>
                </div>
                <p>© {{ now()->year }} Soffee · Herramientas digitales para organizar mejor el trabajo docente.</p>
            </div>
        </footer>
    </div>
</body>
</html>
