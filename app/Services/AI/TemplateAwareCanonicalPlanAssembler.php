<?php

namespace App\Services\AI;

use App\Data\Planning\AdaptiveGeneratedPlan;
use App\Data\Planning\CanonicalPlan;
use App\Data\Planning\GeneratedPlanDraft;
use App\Models\PlanningRequest;

final class TemplateAwareCanonicalPlanAssembler extends CanonicalPlanAssembler
{
    public function assemble(PlanningRequest $request, GeneratedPlanDraft|AdaptiveGeneratedPlan $draft): CanonicalPlan
    {
        if ($draft instanceof AdaptiveGeneratedPlan) {
            return app(AdaptiveCanonicalPlanAssembler::class)->assemble($request, $draft);
        }

        // La generación v1 es canónica y su contrato quedó congelado sin
        // contexto de formato. Una preferencia o elección de exportación no
        // puede introducir campos custom después de recibir la respuesta IA.
        return parent::assemble($request, $draft);
    }
}
