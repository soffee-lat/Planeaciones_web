<?php

namespace App\Enums;

/**
 * Tipo de recurso comercial reservado.
 *
 * Verbatim DATABASE.md § usage_reservations:
 *   resource (planning/human_review/client_correction)
 *
 * planning y human_review consumen contra el entitlement del periodo.
 * client_correction se contabiliza POR SOLICITUD (correction_limit del
 * plan_version_snapshot del propio PlanningRequest) y NO contra una cuota
 * periódica: en 3B el ledger acepta el valor pero no lo enlaza a periodos.
 */
enum UsageResource: string
{
    case Planning = 'planning';
    case HumanReview = 'human_review';
    case ClientCorrection = 'client_correction';

    /** Recursos que consumen del entitlement del periodo. */
    public function consumesPeriodEntitlement(): bool
    {
        return $this === self::Planning || $this === self::HumanReview;
    }
}
