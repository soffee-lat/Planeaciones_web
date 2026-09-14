<div class="space-y-4 text-sm break-words">
    @if (! $summary['has_plan'])
        <div class="pd-card-soft flex items-start gap-3 p-4">
            <span class="pd-icon-tile">
                <x-filament::icon icon="heroicon-o-information-circle" class="h-5 w-5" />
            </span>
            <div>
                <div class="text-sm font-bold text-gray-950 dark:text-white">Aún no tienes un plan activo</div>
                <p class="mt-1 leading-6 pd-muted">Puedes guardar y confirmar tu planeación. Quedará pendiente de activar hasta que tengas un plan con unidades disponibles.</p>
            </div>
        </div>
    @else
        <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-4">
            <div class="pd-card-soft p-3">
                <div class="text-xs font-semibold pd-muted">Plan</div>
                <div class="mt-1 font-bold text-gray-950 dark:text-white">{{ $summary['plan_name'] }}</div>
            </div>
            <div class="pd-card-soft p-3">
                <div class="text-xs font-semibold pd-muted">Periodo vigente</div>
                <div class="mt-1 font-bold text-gray-950 dark:text-white">{{ $summary['period_start'] }} a {{ $summary['period_end'] }}</div>
            </div>
            <div class="pd-card-soft p-3">
                <div class="text-xs font-semibold pd-muted">Unidades disponibles</div>
                <div class="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">{{ $summary['planning']['available'] }}</div>
            </div>
            <div class="pd-card-soft p-3">
                <div class="text-xs font-semibold pd-muted">Correcciones incluidas</div>
                <div class="mt-1 text-xl font-extrabold text-gray-950 dark:text-white">{{ $summary['correction_limit'] }}</div>
            </div>
        </div>

        <div class="pd-card-soft p-4">
            <div class="flex items-start gap-3">
                <span class="pd-icon-tile">
                    <x-filament::icon icon="heroicon-o-calculator" class="h-5 w-5" />
                </span>
                <div class="min-w-0 flex-1">
                    <div class="text-sm font-bold text-gray-950 dark:text-white">Consumo estimado</div>
                    <p class="mt-1 leading-6 pd-muted">Cada unidad cubre hasta {{ $summary['max_days'] }} días naturales consecutivos, incluidos fines de semana y festivos.</p>
                    @if ($summary['units'] !== null)
                        <div class="mt-3 flex flex-wrap gap-2">
                            <span class="pd-chip">{{ $summary['days'] }} días naturales</span>
                            <span class="pd-chip">{{ $summary['units'] }} unidades necesarias</span>
                            <span class="pd-chip">{{ $summary['planning']['reserved'] }} reservadas</span>
                            <span class="pd-chip">{{ $summary['planning']['consumed'] }} usadas</span>
                        </div>
                    @endif
                </div>
            </div>
        </div>

        @if ($summary['human_required'])
            <div class="pd-card-soft p-4">
                <div class="text-sm font-bold text-gray-950 dark:text-white">Revisión humana incluida</div>
                <p class="mt-1 pd-muted">{{ $summary['human_review']['consumed'] }} usadas · {{ $summary['human_review']['reserved'] }} reservadas · {{ $summary['human_review']['available'] }} disponibles.</p>
                @if ($summary['units'] !== null)
                    <p class="mt-2 text-xs pd-muted">Se reservarán también {{ $summary['units'] }} unidades de revisión humana.</p>
                @endif
            </div>
        @else
            <div class="pd-card-soft p-4 text-sm pd-muted">Este plan no incluye revisión humana.</div>
        @endif

        <div class="flex flex-wrap gap-2">
            <span class="pd-chip">Grupos activos permitidos: {{ $summary['group_limit'] }}</span>
            <span class="pd-chip">Rondas por solicitud: {{ $summary['correction_limit'] }}</span>
        </div>

        @if ($summary['units'] !== null)
            @if ($summary['units'] > $summary['planning']['available'])
                <div class="rounded-xl border border-warning-300/50 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-500/20 dark:bg-warning-950/20 dark:text-warning-300">
                    Necesitas {{ $summary['units'] }} unidades y tienes {{ $summary['planning']['available'] }} disponibles. Puedes confirmar; la activación esperará a que tengas saldo suficiente.
                </div>
            @elseif ($summary['human_required'] && $summary['units'] > $summary['human_review']['available'])
                <div class="rounded-xl border border-warning-300/50 bg-warning-50 p-3 text-sm text-warning-700 dark:border-warning-500/20 dark:bg-warning-950/20 dark:text-warning-300">
                    No tienes suficientes unidades de revisión humana para activar esta planeación. No se reservarán unidades parcialmente.
                </div>
            @endif
            <p class="text-xs pd-muted">El saldo se verifica de nuevo al activar. Se reservan unidades; el procesamiento aún no comienza.</p>
        @endif
    @endif
</div>
