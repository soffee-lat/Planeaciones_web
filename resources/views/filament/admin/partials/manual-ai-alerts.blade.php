@auth
    @if (auth()->user()->hasRole(\App\Enums\RoleCode::Administrator))
        <div
            id="soffee-ai-alert"
            class="fixed bottom-4 right-4 z-[9999] hidden w-[min(92vw,28rem)] rounded-xl border border-warning-300 bg-white p-4 shadow-2xl dark:border-warning-700 dark:bg-gray-900"
            role="status"
            aria-live="assertive"
        >
            <div class="flex items-start justify-between gap-3">
                <div>
                    <div class="text-sm font-semibold text-gray-950 dark:text-white">Acción requerida en Planeaciones</div>
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
                <a
                    id="soffee-review-alert-open"
                    href="{{ \App\Filament\Admin\Pages\ClientCorrections::getUrl(panel: 'admin') }}"
                    class="inline-flex items-center justify-center rounded-lg bg-warning-600 px-3 py-2 text-sm font-semibold text-white hover:bg-warning-500"
                >
                    Abrir revisiones
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
            Activar alertas del operador
        </button>

        <script data-navigate-once>
            (() => {
                if (window.__soffeeManualAiAlertsBooted) {
                    return;
                }

                window.__soffeeManualAiAlertsBooted = true;

                const endpoint = @js(route('admin.ai-operations.summary'));
                const operationsUrl = @js(\App\Filament\Admin\Pages\AiOperations::getUrl(panel: 'admin'));
                const reviewsUrl = @js(\App\Filament\Admin\Pages\ClientCorrections::getUrl(panel: 'admin'));
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
                const aiButton = () => document.getElementById('soffee-ai-alert-open');
                const reviewButton = () => document.getElementById('soffee-review-alert-open');

                const alertsEnabled = () => localStorage.getItem(enabledKey) === '1';
                const aiCount = (data) => Number(data.count || 0);
                const reviewCount = (data) => Number(data.client_reviews?.count || 0);
                const totalCount = (data) => aiCount(data) + reviewCount(data);

                const ensureAudio = async () => {
                    const AudioContextClass = window.AudioContext || window.webkitAudioContext;
                    if (! AudioContextClass) return null;

                    audioContext ??= new AudioContextClass();
                    if (audioContext.state === 'suspended') await audioContext.resume();

                    return audioContext;
                };

                const beep = async (frequency = 880, duration = 0.16) => {
                    if (! alertsEnabled()) return;

                    try {
                        const context = await ensureAudio();
                        if (! context) return;

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
                        // La alerta visual permanece disponible si el navegador bloquea audio.
                    }
                };

                const soundBurst = async () => {
                    await beep(880, 0.14);
                    setTimeout(() => beep(1040, 0.14), 230);
                    setTimeout(() => beep(880, 0.20), 460);
                };

                const messageParts = (data) => {
                    const parts = [];
                    if (data.by_stage?.generation) parts.push(String(data.by_stage.generation) + ' generación');
                    if (data.by_stage?.audit) parts.push(String(data.by_stage.audit) + ' auditoría');
                    if (data.by_stage?.correction) parts.push(String(data.by_stage.correction) + ' corrección');
                    if (reviewCount(data)) parts.push(String(reviewCount(data)) + ' revisión de cliente');

                    return parts;
                };

                const desktopNotification = (data) => {
                    if (! ('Notification' in window) || Notification.permission !== 'granted') return;

                    try {
                        const reviews = reviewCount(data);
                        const ai = aiCount(data);
                        const destination = reviews > 0 && ai === 0 ? reviewsUrl : operationsUrl;
                        const notification = new Notification('Soffee · Acción requerida', {
                            body: messageParts(data).join(' · ') || String(totalCount(data)) + ' tarea(s) pendiente(s)',
                            tag: 'soffee-operator-pending',
                            renotify: true,
                            requireInteraction: true,
                        });

                        notification.onclick = () => {
                            window.focus();
                            window.location.href = destination;
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
                    const total = totalCount(data);

                    if (! alertRoot || ! count || ! message) return;

                    if (total < 1) {
                        alertRoot.classList.add('hidden');
                        document.title = originalTitle;
                        localStorage.removeItem(ackKey);
                        return;
                    }

                    count.textContent = String(total);
                    message.textContent = messageParts(data).join(' · ') || String(total) + ' tarea(s) pendiente(s)';
                    aiButton()?.classList.toggle('hidden', aiCount(data) < 1);
                    reviewButton()?.classList.toggle('hidden', reviewCount(data) < 1);

                    const acknowledged = localStorage.getItem(ackKey);
                    alertRoot.classList.toggle('hidden', acknowledged === data.signature);
                    document.title = '(' + String(total) + ') ' + originalTitle;
                };

                const remindIfNeeded = (data) => {
                    if (! alertsEnabled() || totalCount(data) < 1) return;
                    if (localStorage.getItem(ackKey) === data.signature) return;

                    const now = Date.now();
                    const lastReminder = Number(localStorage.getItem(lastReminderKey) || 0);

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

                        if (! response.ok) return;

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
                    if (button) button.classList.toggle('hidden', alertsEnabled());
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
                            // El permiso puede ser rechazado; sonido y UI siguen disponibles.
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
                        if (currentSignature) localStorage.setItem(ackKey, currentSignature);
                        root()?.classList.add('hidden');
                        return;
                    }

                    if ((target?.closest('#soffee-ai-alert-open') || target?.closest('#soffee-review-alert-open')) && currentSignature) {
                        localStorage.setItem(ackKey, currentSignature);
                    }
                });

                const primeAudio = () => {
                    if (alertsEnabled()) ensureAudio();
                };

                window.addEventListener('pointerdown', primeAudio, { once: true });
                window.addEventListener('keydown', primeAudio, { once: true });

                refreshActivationButton();
                poll();

                window.setInterval(poll, 15000);
                window.addEventListener('focus', poll);
                document.addEventListener('visibilitychange', () => {
                    if (document.visibilityState === 'visible') poll();
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
