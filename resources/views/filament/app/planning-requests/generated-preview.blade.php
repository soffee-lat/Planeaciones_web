@php
    $planning = is_array($plan['planning'] ?? null) ? $plan['planning'] : [];
    $pedagogy = is_array($plan['pedagogical_design'] ?? null) ? $plan['pedagogical_design'] : [];
    $assessment = is_array($plan['assessment_plan'] ?? null) ? $plan['assessment_plan'] : [];
    $sessions = is_array($plan['sessions'] ?? null) ? $plan['sessions'] : [];
@endphp

<x-filament::section>
    <x-slot name="heading">Vista previa de la planeación generada</x-slot>
    <x-slot name="description">
        Esta vista resume la versión canónica actual. El DOCX/PDF final se genera con tu formato preferido o con el formato estándar disponible.
    </x-slot>

    <div class="space-y-5 text-sm">
        <div>
            <div class="text-lg font-semibold text-gray-950 dark:text-white">
                {{ $planning['title'] ?? 'Planeación didáctica' }}
            </div>
            @if (! empty($planning['project_name']))
                <div class="mt-1 text-gray-600 dark:text-gray-300">Proyecto: {{ $planning['project_name'] }}</div>
            @endif
            @if (! empty($planning['topic']))
                <div class="text-gray-600 dark:text-gray-300">Tema: {{ $planning['topic'] }}</div>
            @endif
        </div>

        @if (! empty($pedagogy['purpose']))
            <div>
                <div class="font-medium text-gray-950 dark:text-white">Propósito</div>
                <div class="mt-1 text-gray-700 dark:text-gray-200">{{ $pedagogy['purpose'] }}</div>
            </div>
        @endif

        @if ($sessions !== [])
            <div class="space-y-3">
                <div class="font-medium text-gray-950 dark:text-white">Secuencia de sesiones</div>
                @foreach ($sessions as $session)
                    @php
                        $moments = is_array($session['moments'] ?? null) ? $session['moments'] : [];
                    @endphp
                    <div class="rounded-xl border border-gray-200 p-4 dark:border-white/10">
                        <div class="font-semibold text-gray-950 dark:text-white">
                            Sesión {{ $session['sequence'] ?? $loop->iteration }}@if (! empty($session['title'])) · {{ $session['title'] }}@endif
                        </div>
                        @if (! empty($session['specific_goal']))
                            <div class="mt-1 text-gray-600 dark:text-gray-300">{{ $session['specific_goal'] }}</div>
                        @endif
                        @foreach ($moments as $moment)
                            @php
                                $activities = is_array($moment['activities'] ?? null) ? $moment['activities'] : [];
                            @endphp
                            <div class="mt-3">
                                <div class="font-medium text-gray-900 dark:text-gray-100">
                                    {{ ucfirst((string) ($moment['type'] ?? 'Momento')) }}@if (isset($moment['minutes'])) · {{ $moment['minutes'] }} min@endif
                                </div>
                                <ul class="mt-1 list-disc space-y-1 ps-5 text-gray-700 dark:text-gray-200">
                                    @foreach ($activities as $activity)
                                        @if (! empty($activity['instruction']))
                                            <li>{{ $activity['instruction'] }}</li>
                                        @endif
                                    @endforeach
                                </ul>
                            </div>
                        @endforeach
                    </div>
                @endforeach
            </div>
        @endif

        @if (! empty($assessment['closure']))
            <div>
                <div class="font-medium text-gray-950 dark:text-white">Cierre y evaluación</div>
                <div class="mt-1 text-gray-700 dark:text-gray-200">{{ $assessment['closure'] }}</div>
            </div>
        @endif

        <div class="border-t border-gray-200 pt-3 text-xs text-gray-500 dark:border-white/10 dark:text-gray-400">
            Versión {{ $version->number }} · {{ $version->status instanceof \BackedEnum ? $version->status->value : $version->status }}
            @if ($renderRun)
                · Formato #{{ $renderRun->format_version_id }} · {{ $renderRun->renderer_version }}
            @endif
        </div>
    </div>
</x-filament::section>
