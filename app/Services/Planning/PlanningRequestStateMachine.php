<?php

namespace App\Services\Planning;

use App\Enums\PlanningRequestStatus;
use RuntimeException;

final class PlanningRequestStateMachine
{
    /** @var array<string,list<string>> */
    private const ALLOWED = [
        'BORRADOR' => ['ESPERANDO_INFORMACION', 'ESPERANDO_PAGO', 'LISTA_PARA_PROCESAR', 'CANCELADA'],
        'ESPERANDO_INFORMACION' => ['ESPERANDO_PAGO', 'LISTA_PARA_PROCESAR', 'CANCELADA'],
        'ESPERANDO_PAGO' => ['ESPERANDO_INFORMACION', 'LISTA_PARA_PROCESAR', 'CANCELADA'],
        'LISTA_PARA_PROCESAR' => ['GENERACION_IA', 'CANCELADA'],
        'GENERACION_IA' => ['AUDITORIA_IA', 'CANCELADA'],
        'AUDITORIA_IA' => ['APROBADA', 'REVISION_HUMANA', 'CORRECCION_IA', 'CANCELADA'],
        'REVISION_HUMANA' => ['APROBADA', 'CORRECCION_IA', 'CANCELADA'],
        'CORRECCION_IA' => ['AUDITORIA_IA', 'CANCELADA'],
        'APROBADA' => ['GENERANDO_DOCUMENTO', 'CANCELADA'],
        'GENERANDO_DOCUMENTO' => ['LISTA_PARA_ENTREGAR', 'CANCELADA'],
        'LISTA_PARA_ENTREGAR' => ['ENTREGADA', 'CANCELADA'],
        'ENTREGADA' => ['COMPLETADA', 'CORRECCION_SOLICITADA'],
        'COMPLETADA' => ['CORRECCION_SOLICITADA'],
        'CORRECCION_SOLICITADA' => ['CORRECCION_IA', 'ENTREGADA', 'COMPLETADA'],
        'CANCELADA' => [],
    ];

    public function assertCanTransition(PlanningRequestStatus $from, PlanningRequestStatus $to): void
    {
        if ($from === $to || ! in_array($to->value, self::ALLOWED[$from->value] ?? [], true)) {
            throw new RuntimeException('PLANNING_REQUEST_TRANSITION_NOT_ALLOWED:' . $from->value . ':' . $to->value);
        }
    }

    public function canTransition(PlanningRequestStatus $from, PlanningRequestStatus $to): bool
    {
        try {
            $this->assertCanTransition($from, $to);
            return true;
        } catch (RuntimeException) {
            return false;
        }
    }
}
