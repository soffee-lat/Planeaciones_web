<x-filament::section>
    <x-slot name="heading">Antes de terminar</x-slot>
    <x-slot name="description">
        Son tres preguntas cortas para saber qué parte de Planeaciones realmente te ayudó.
    </x-slot>

    @if (session('pilot_feedback_status'))
        <div class="mb-4 rounded-lg bg-success-50 p-3 text-sm text-success-700 dark:bg-success-950/30 dark:text-success-300">
            {{ session('pilot_feedback_status') }}
        </div>
    @endif

    @if ($errors->has('pilot_feedback'))
        <div class="mb-4 rounded-lg bg-danger-50 p-3 text-sm text-danger-700 dark:bg-danger-950/30 dark:text-danger-300">
            {{ $errors->first('pilot_feedback') }}
        </div>
    @endif

    @if ($feedback)
        <div class="space-y-3 text-sm">
            <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                <div class="font-medium text-gray-950 dark:text-white">Feedback registrado</div>
                <dl class="mt-3 space-y-2 text-gray-700 dark:text-gray-200">
                    <div>
                        <dt class="font-medium">Tiempo ahorrado</dt>
                        <dd>{{ $savedTimeOptions[$feedback->saved_time_bucket] ?? $feedback->saved_time_bucket }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">Lo que más ayudó</dt>
                        <dd>{{ $helpfulOptions[$feedback->most_helpful] ?? $feedback->most_helpful }}</dd>
                    </div>
                    <div>
                        <dt class="font-medium">¿La usarías para tu siguiente planeación real?</dt>
                        <dd>{{ $nextPlanningOptions[$feedback->next_real_planning] ?? $feedback->next_real_planning }}</dd>
                    </div>
                </dl>
            </div>
            <p class="text-xs text-gray-500 dark:text-gray-400">
                Estas respuestas quedan congeladas para no alterar los resultados del piloto.
            </p>
        </div>
    @else
        <form method="POST" action="{{ route('planning.feedback.store', ['planningRequest' => $request->id]) }}" class="space-y-5">
            @csrf

            <div>
                <label for="saved_time_bucket" class="block text-sm font-medium text-gray-950 dark:text-white">
                    1. ¿Cuánto tiempo calculas que te ahorró esta planeación?
                </label>
                <select id="saved_time_bucket" name="saved_time_bucket" required class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
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
                <label for="most_helpful" class="block text-sm font-medium text-gray-950 dark:text-white">
                    2. ¿Qué fue lo que más te ayudó?
                </label>
                <select id="most_helpful" name="most_helpful" required class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
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
                <label for="next_real_planning" class="block text-sm font-medium text-gray-950 dark:text-white">
                    3. ¿Usarías Planeaciones para tu siguiente planeación real?
                </label>
                <select id="next_real_planning" name="next_real_planning" required class="mt-2 block w-full rounded-lg border-gray-300 bg-white text-sm dark:border-white/10 dark:bg-white/5">
                    <option value="">Selecciona una opción</option>
                    @foreach ($nextPlanningOptions as $value => $label)
                        <option value="{{ $value }}" @selected(old('next_real_planning') === $value)>{{ $label }}</option>
                    @endforeach
                </select>
                @error('next_real_planning')
                    <p class="mt-1 text-sm text-danger-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="flex items-center justify-between gap-4">
                <p class="text-xs text-gray-500 dark:text-gray-400">
                    No se guarda información de alumnos en estas respuestas.
                </p>
                <x-filament::button type="submit">Enviar respuestas</x-filament::button>
            </div>
        </form>
    @endif
</x-filament::section>
