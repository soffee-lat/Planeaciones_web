<?php

namespace App\Enums;

/**
 * Estados de una PlanningRequest. Solo BORRADOR y ESPERANDO_PAGO se
 * implementan en la Subfase 2C: la solicitud nace en BORRADOR y, al
 * confirmar el snapshot, transita a ESPERANDO_PAGO porque el módulo
 * comercial (planes, suscripciones, reservas) aún no existe. Los demás
 * estados están declarados para respetar la máquina de estados de
 * WORKFLOWS.md pero NO tienen transiciones implementadas todavía.
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
