@if (session('pilot_feedback_status'))
    <div class="mb-3 rounded-xl border border-success-300/50 bg-success-50 p-3 text-sm text-success-700 dark:border-success-500/20 dark:bg-success-950/30 dark:text-success-300">
        {{ session('pilot_feedback_status') }}
    </div>
@endif

@if ($errors->has('pilot_feedback'))
    <div class="mb-3 rounded-xl border border-danger-300/50 bg-danger-50 p-3 text-sm text-danger-700 dark:border-danger-500/20 dark:bg-danger-950/30 dark:text-danger-300">
        {{ $errors->first('pilot_feedback') }}
    </div>
@endif

@if ($feedback)
    <div class="pd-feedback-shell">
        <div class="pd-feedback-summary">
            <div class="pd-feedback-summary__main">
                <span class="pd-feedback-icon">
                    <x-filament::icon icon="heroicon-o-check-circle" class="h-5 w-5" />
                </span>
                <div>
                    <div class="text-sm font-bold text-gray-950 dark:text-white">Gracias por ayudarnos a mejorar</div>
                    <p class="mt-1 text-xs pd-muted">Tus respuestas ya quedaron registradas.</p>
                </div>
            </div>
            <span class="pd-chip">Encuesta respondida</span>
        </div>
    </div>
@else
    <details class="pd-feedback-shell" @if ($errors->any()) open @endif>
        <summary class="pd-feedback-summary">
            <div class="pd-feedback-summary__main">
                <span class="pd-feedback-icon">
                    <x-filament::icon icon="heroicon-o-chart-bar" class="h-5 w-5" />
                </span>
                <div>
                    <div class="text-sm font-bold text-gray-950 dark:text-white">Ayúdanos a mejorar</div>
                    <p class="mt-1 text-xs pd-muted">Tu opinión es muy importante para seguir mejorando. Cuéntanos tu experiencia con esta planeación.</p>
                </div>
            </div>
            <span class="pd-chip">Responder encuesta</span>
        </summary>

        <div class="pd-feedback-body">
            <form method="POST" action="{{ route('planning.feedback.store', ['planningRequest' => $request->id]) }}" class="grid gap-4 lg:grid-cols-3">
                @csrf

                <div>
                    <label for="saved_time_bucket" class="block text-sm font-semibold text-gray-950 dark:text-white">
                        1. ¿Cuánto tiempo te ahorró?
                    </label>
                    <select id="saved_time_bucket" name="saved_time_bucket" required class="mt-2 block w-full rounded-xl border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="">Selecciona una opción</option>
                        @foreach ($savedTimeOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('saved_time_bucket') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('saved_time_bucket')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="most_helpful" class="block text-sm font-semibold text-gray-950 dark:text-white">
                        2. ¿Qué fue lo que más te ayudó?
                    </label>
                    <select id="most_helpful" name="most_helpful" required class="mt-2 block w-full rounded-xl border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="">Selecciona una opción</option>
                        @foreach ($helpfulOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('most_helpful') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('most_helpful')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div>
                    <label for="next_real_planning" class="block text-sm font-semibold text-gray-950 dark:text-white">
                        3. ¿La usarías nuevamente?
                    </label>
                    <select id="next_real_planning" name="next_real_planning" required class="mt-2 block w-full rounded-xl border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                        <option value="">Selecciona una opción</option>
                        @foreach ($nextPlanningOptions as $value => $label)
                            <option value="{{ $value }}" @selected(old('next_real_planning') === $value)>{{ $label }}</option>
                        @endforeach
                    </select>
                    @error('next_real_planning')
                        <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                    @enderror
                </div>

                <div class="flex flex-col gap-3 border-t border-gray-200 pt-4 lg:col-span-3 lg:flex-row lg:items-center lg:justify-between dark:border-white/10">
                    <p class="text-xs pd-muted">No se guarda información de alumnos en estas respuestas.</p>
                    <x-filament::button type="submit" icon="heroicon-o-paper-airplane">Enviar respuestas</x-filament::button>
                </div>
            </form>
        </div>
    </details>
@endif
