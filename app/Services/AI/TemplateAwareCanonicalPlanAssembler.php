<?php

namespace App\Services\AI;

use App\Data\Planning\CanonicalPlan;
use App\Data\Planning\GeneratedPlanDraft;
use App\Models\PlanningRequest;

final class TemplateAwareCanonicalPlanAssembler extends CanonicalPlanAssembler
{
    public function assemble(PlanningRequest $request, GeneratedPlanDraft $draft): CanonicalPlan
    {
        $canonical = parent::assemble($request, $draft);
        $generated = $draft->toArray();
        $custom = $generated['custom'] ?? null;

        if (! is_array($custom) || $custom === []) {
            return $canonical;
        }

        $payload = $canonical->toArray();
        $payload['custom'] = $custom;

        return app(CanonicalPlanValidator::class)->validate($payload);
    }
}
