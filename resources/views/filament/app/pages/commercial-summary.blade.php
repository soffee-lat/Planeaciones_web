<div class="space-y-3 text-sm break-words">
    @if (! $summary['has_plan'])
        <p>No tienes un plan activo con un periodo vigente.</p>
        <p>Puedes guardar y confirmar tu planeación. Quedará pendiente de activar hasta que tengas un plan con unidades disponibles.</p>
    @else
        <p><strong>Plan:</strong> {{ $summary['plan_name'] }}</p>
        <p><strong>Periodo vigente:</strong> {{ $summary['period_start'] }} a {{ $summary['period_end'] }}</p>
        <p>Cada unidad cubre hasta {{ $summary['max_days'] }} días naturales consecutivos, incluidos fines de semana y festivos.</p>
        @if ($summary['units'] !== null)
            <p><strong>Esta planeación:</strong> {{ $summary['days'] }} días naturales · {{ $summary['units'] }} unidades necesarias.</p>
        @endif
        <p><strong>Unidades de planeación:</strong> {{ $summary['planning']['consumed'] }} usadas · {{ $summary['planning']['reserved'] }} reservadas · {{ $summary['planning']['available'] }} disponibles.</p>
        @if ($summary['human_required'])
            <p><strong>Revisión humana incluida.</strong> {{ $summary['human_review']['consumed'] }} usadas · {{ $summary['human_review']['reserved'] }} reservadas · {{ $summary['human_review']['available'] }} disponibles.</p>
            @if ($summary['units'] !== null)
                <p>Se reservarán también {{ $summary['units'] }} unidades de revisión humana.</p>
            @endif
        @else
            <p>Este plan no incluye revisión humana.</p>
        @endif
        <p><strong>Grupos activos permitidos:</strong> {{ $summary['group_limit'] }}. <strong>Rondas de corrección por solicitud:</strong> {{ $summary['correction_limit'] }}.</p>
        @if ($summary['units'] !== null)
            @if ($summary['units'] > $summary['planning']['available'])
                <p>Necesitas {{ $summary['units'] }} unidades y tienes {{ $summary['planning']['available'] }} disponibles. Puedes confirmar; la activación esperará a que tengas saldo suficiente.</p>
            @elseif ($summary['human_required'] && $summary['units'] > $summary['human_review']['available'])
                <p>No tienes suficientes unidades de revisión humana para activar esta planeación. No se reservarán unidades parcialmente.</p>
            @endif
            <p>El saldo se verifica de nuevo al activar. Se reservan unidades; el procesamiento aún no comienza.</p>
        @endif
    @endif
</div>
