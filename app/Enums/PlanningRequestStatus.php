<?php

namespace App\Enums;

/**
 * Estados de una PlanningRequest. Las transiciones se materializan por fases
 * mediante Actions de dominio; desde Fase 4B el arranque del pipeline usa
 * PlanningRequestStateMachine y conserva RequestStateEvent. Declarar un valor
 * aquí no autoriza por sí mismo una transición.
 */
enum PlanningRequestStatus: string
{
    case BORRADOR = 'BORRADOR';
    case ESPERANDO_INFORMACION = 'ESPERANDO_INFORMACION';
    case ESPERANDO_PAGO = 'ESPERANDO_PAGO';
    case LISTA_PARA_PROCESAR = 'LISTA_PARA_PROCESAR';
    case GENERACION_IA = 'GENERACION_IA';
    case AUDITORIA_IA = 'AUDITORIA_IA';
    case CORRECCION_IA = 'CORRECCION_IA';
    case REVISION_HUMANA = 'REVISION_HUMANA';
    case APROBADA = 'APROBADA';
    case GENERANDO_DOCUMENTO = 'GENERANDO_DOCUMENTO';
    case LISTA_PARA_ENTREGAR = 'LISTA_PARA_ENTREGAR';
    case ENTREGADA = 'ENTREGADA';
    case COMPLETADA = 'COMPLETADA';
    case CORRECCION_SOLICITADA = 'CORRECCION_SOLICITADA';
    case CANCELADA = 'CANCELADA';

    public function isDraft(): bool
    {
        return $this === self::BORRADOR;
    }

    public function isConfirmed(): bool
    {
        return $this !== self::BORRADOR && $this !== self::CANCELADA;
    }
}
