@auth
    @if (auth()->user()->hasRole(\App\Enums\RoleCode::Administrator))
        <div
            id="soffee-ai-alert"
            class="fixed bottom-4 right-4 z-[9999] hidden w-[min(92vw,26rem)] rounded-xl border border-warning-300 bg-white p-4 shadow-2xl dark:border-warning-700 dark:bg-gray-900"
            role="status"
            aria-live="assertive"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-gray-950 dark:text-white">
                        Planeación esperando procesamiento
                    </div>
                    <div id="soffee-ai-alert-message" class="mt-1 text-sm text-gray-600 dark:text-gray-300"></div>
                </div>
                <span
                    id="soffee-ai-alert-count"
                    class="rounded-full bg-warning-100 px-2.5 py-1 text-xs font-bold text-warning-800 dark:bg-warning-900 dark:text-warning-200"
                ></span>
            </div>

            <div class="mt-4 flex flex-wrap gap-2">
                <a
                    id="soffee-ai-alert-open"
                    href="{{ \App\Filament\Admin\Pages\AiOperations::getUrl(panel: 'admin') }}"
                    class="inline-flex items-center justify-center rounded-lg bg-primary-600 px-3 py-2 text-sm font-semibold text-white hover:bg-primary-500"
                >
                    Abrir Operación IA
                </a>
                <button
                    id="soffee-ai-alert-ack"
                    type="button"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-300 px-3 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50 dark:border-gray-700 dark:text-gray-200 dark:hover:bg-gray-800"
                >
                    Marcar visto
                </button>
            </div>
        </div>

        <button
            id="soffee-ai-enable-alerts"
            type="button"
            class="fixed bottom-4 left-4 z-[9999] hidden rounded-lg border border-gray-300 bg-white px-3 py-2 text-xs font-semibold text-gray-700 shadow-lg hover:bg-gray-50 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-200 dark:hover:bg-gray-800"
        >
            Activar alertas IA
        </button>

        <script data-navigate-once>
            (() => {
                if (window.__soffeeManualAiAlertsBooted) {
                    return;
                }

                window.__soffeeManualAiAlertsBooted = true;

                const endpoint = @js(route('admin.ai-operations.summary'));
                const operationsUrl = @js(\App\Filament\Admin\Pages\AiOperations::getUrl(panel: 'admin'));
                const storagePrefix = 'soffee.manualAi.';
                const enabledKey = storagePrefix + 'alertsEnabled';
                const ackKey = storagePrefix + 'ackSignature';
                const lastReminderKey = storagePrefix + 'lastReminderAt';

                let currentSignature = '';
                let audioContext = null;
                let originalTitle = document.title.replace(/^\(\d+\)\s+/, '');

                const root = () => document.getElementById('soffee-ai-alert');
                const countNode = () => document.getElementById('soffee-ai-alert-count');
                const messageNode = () => document.getElementById('soffee-ai-alert-message');
                const enableButton = () => document.getElementById('soffee-ai-enable-alerts');

                const alertsEnabled = () => localStorage.getItem(enabledKey) === '1';

                const ensureAudio = async () => {
                    const AudioContextClass = window.AudioContext || window.webkitAudioContext;

                    if (! AudioContextClass) {
                        return null;
                    }

                    audioContext ??= new AudioContextClass();

                    if (audioContext.state === 'suspended') {
                        await audioContext.resume();
                    }

                    return audioContext;
                };

                const beep = async (frequency = 880, duration = 0.16) => {
                    if (! alertsEnabled()) {
                        return;
                    }

                    try {
                        const context = await ensureAudio();

                        if (! context) {
                            return;
                        }

                        const oscillator = context.createOscillator();
                        const gain = context.createGain();
                        const now = context.currentTime;

                        oscillator.type = 'sine';
                        oscillator.frequency.setValueAtTime(frequency, now);
                        gain.gain.setValueAtTime(0.0001, now);
                        gain.gain.exponentialRampToValueAtTime(0.18, now + 0.02);
                        gain.gain.exponentialRampToValueAtTime(0.0001, now + duration);

                        oscillator.connect(gain);
                        gain.connect(context.destination);
                        oscillator.start(now);
                        oscillator.stop(now + duration + 0.02);
                    } catch (error) {
                        // Algunos navegadores bloquean audio hasta una interacción
                        // explícita. El botón "Activar alertas IA" resuelve ese caso.
                    }
                };

                const soundBurst = async () => {
                    await beep(880, 0.14);
                    setTimeout(() => beep(1040, 0.14), 230);
                    setTimeout(() => beep(880, 0.20), 460);
                };

                const desktopNotification = (data) => {
                    if (! ('Notification' in window) || Notification.permission !== 'granted') {
                        return;
                    }

                    try {
                        const notification = new Notification('Soffee · Planeación pendiente', {
                            body: String(data.count) + ' tarea(s) de IA requieren procesamiento manual.',
                            tag: 'soffee-manual-ai-pending',
                            renotify: true,
                            requireInteraction: true,
                        });

                        notification.onclick = () => {
                            window.focus();
                            window.location.href = operationsUrl;
                            notification.close();
                        };
                    } catch (error) {
                        // La alerta visual dentro del panel permanece disponible.
                    }
                };

                const setVisualState = (data) => {
                    const alertRoot = root();
                    const count = countNode();
                    const message = messageNode();

                    if (! alertRoot || ! count || ! message) {
                        return;
                    }

                    if (data.count < 1) {
                        alertRoot.classList.add('hidden');
                        document.title = originalTitle;
                        localStorage.removeItem(ackKey);
                        return;
                    }

                    const parts = [];
                    if (data.by_stage?.generation) parts.push(String(data.by_stage.generation) + ' generación');
                    if (data.by_stage?.audit) parts.push(String(data.by_stage.audit) + ' auditoría');
                    if (data.by_stage?.correction) parts.push(String(data.by_stage.correction) + ' corrección');

                    count.textContent = String(data.count);
                    message.textContent = parts.length
                        ? parts.join(' · ')
                        : String(data.count) + ' tarea(s) pendiente(s)';
                    const acknowledged = localStorage.getItem(ackKey);
                    alertRoot.classList.toggle('hidden', acknowledged === data.signature);
                    document.title = '(' + String(data.count) + ') ' + originalTitle;
                };

                const remindIfNeeded = (data) => {
                    if (! alertsEnabled() || data.count < 1) {
                        return;
                    }

                    const acknowledged = localStorage.getItem(ackKey);
                    if (acknowledged === data.signature) {
                        return;
                    }

                    const now = Date.now();
                    const lastReminder = Number(localStorage.getItem(lastReminderKey) || 0);

                    // Repite el aviso mientras haya trabajo no reconocido. Así un
                    // sonido perdido no deja una planeación detenida silenciosamente.
                    if (now - lastReminder >= 60000) {
                        localStorage.setItem(lastReminderKey, String(now));
                        soundBurst();
                        desktopNotification(data);
                    }
                };

                const poll = async () => {
                    try {
                        const response = await fetch(endpoint, {
                            credentials: 'same-origin',
                            headers: {
                                'Accept': 'application/json',
                                'X-Requested-With': 'XMLHttpRequest',
                            },
                        });

                        if (! response.ok) {
                            return;
                        }

                        const data = await response.json();
                        currentSignature = data.signature || '';

                        setVisualState(data);
                        remindIfNeeded(data);
                    } catch (error) {
                        // Un fallo temporal de red se recupera en el siguiente poll.
                    }
                };

                const refreshActivationButton = () => {
                    const button = enableButton();

                    if (! button) {
                        return;
                    }

                    button.classList.toggle('hidden', alertsEnabled());
                };

                const activateAlerts = async () => {
                    localStorage.setItem(enabledKey, '1');

                    try {
                        await ensureAudio();
                        await soundBurst();
                    } catch (error) {
                        // La alerta visual seguirá funcionando.
                    }

                    if ('Notification' in window && Notification.permission === 'default') {
                        try {
                            await Notification.requestPermission();
                        } catch (error) {
                            // El permiso puede ser rechazado; el sonido y la UI
                            // siguen disponibles mientras el panel esté abierto.
                        }
                    }

                    refreshActivationButton();
                    poll();
                };

                document.addEventListener('click', (event) => {
                    const target = event.target instanceof Element ? event.target : null;

                    if (target?.closest('#soffee-ai-enable-alerts')) {
                        activateAlerts();
                        return;
                    }

                    if (target?.closest('#soffee-ai-alert-ack')) {
                        if (currentSignature) {
                            localStorage.setItem(ackKey, currentSignature);
                        }
                        root()?.classList.add('hidden');
                        return;
                    }

                    if (target?.closest('#soffee-ai-alert-open') && currentSignature) {
                        localStorage.setItem(ackKey, currentSignature);
                    }
                });

                // Los navegadores pueden exigir una interacción en cada sesión para
                // habilitar WebAudio. Si ya activaste las alertas, cualquier clic o
                // tecla en el panel vuelve a preparar el audio sin pedirte nada.
                const primeAudio = () => {
                    if (alertsEnabled()) {
                        ensureAudio();
                    }
                };
                window.addEventListener('pointerdown', primeAudio, { once: true });
                window.addEventListener('keydown', primeAudio, { once: true });

                refreshActivationButton();
                poll();

                window.setInterval(poll, 15000);
                window.addEventListener('focus', poll);
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') {
                        poll();
                    }
                });
                document.addEventListener('livewire:navigated', () => {
                    originalTitle = document.title.replace(/^\(\d+\)\s+/, '');
                    refreshActivationButton();
                    poll();
                });
            })();
        </script>
    @endif
@endauth
