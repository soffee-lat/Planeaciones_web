<?php

namespace App\Services\Commerce;

use App\Actions\Planning\CalculatePlanningCommercialRequirements;
use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use App\Models\User;

class PlanningCommercialPresentation
{
    /** Public display data only; never exposes ledger or payment identifiers. @return array<string, mixed> */
    public function forCustomer(User $customer, ?string $startsOn = null, ?string $endsOn = null): array
    {
        $period = app(CurrentCommercialRights::class)->forCustomer($customer);
        if (! $period) {
            return ['has_plan' => false];
        }
        $balance = app(SubscriptionBalance::class)->forPeriod($period);
        $calculation = null;
        if ($startsOn && $endsOn) {
            try {
                $calculation = app(CalculatePlanningCommercialRequirements::class)->forDates($startsOn, $endsOn, $period->planVersion);
            } catch (\InvalidArgumentException) {
                // The date fields own validation; incomplete input has no quote.
            }
        }

        return [
            'has_plan' => true,
            'plan_name' => $period->planVersion->plan->name,
            'period_start' => $period->starts_at->format('d/m/Y'),
            'period_end' => $period->ends_at->format('d/m/Y'),
            'planning' => $balance['planning']->toArray(),
            'human_review' => $balance['human_review']->toArray(),
            'human_required' => (bool) $period->entitlement_snapshot['human_review_required'],
            'max_days' => $period->entitlement('max_planning_days'),
            'correction_limit' => $period->entitlement('correction_limit'),
            'group_limit' => $period->entitlement('group_limit'),
            'days' => $calculation['planning_days'] ?? null,
            'units' => $calculation['planning_units'] ?? null,
        ];
    }

    public function status(PlanningRequest $request): string
    {
        return match ($request->status) {
            PlanningRequestStatus::BORRADOR => 'Borrador',
            PlanningRequestStatus::ESPERANDO_PAGO => 'Pendiente de activar',
            PlanningRequestStatus::LISTA_PARA_PROCESAR,
            PlanningRequestStatus::GENERACION_IA,
            PlanningRequestStatus::AUDITORIA_IA,
            PlanningRequestStatus::CORRECCION_IA,
            PlanningRequestStatus::APROBADA,
            PlanningRequestStatus::GENERANDO_DOCUMENTO,
            PlanningRequestStatus::LISTA_PARA_ENTREGAR => 'Preparando',
            PlanningRequestStatus::REVISION_HUMANA => 'En revisión',
            PlanningRequestStatus::ESPERANDO_INFORMACION => 'Necesitamos información',
            PlanningRequestStatus::ENTREGADA,
            PlanningRequestStatus::COMPLETADA => 'Lista para descargar',
            PlanningRequestStatus::CORRECCION_SOLICITADA => 'Corrección solicitada',
            PlanningRequestStatus::CANCELADA => 'Cancelada',
        };
    }
}
