<?php

namespace App\Services\AI;

use App\Data\Planning\CanonicalPlan;
use App\Data\Planning\GeneratedPlanDraft;
use App\Exceptions\AiContractException;
use App\Models\PlanningRequest;
use App\Services\Documents\PlanningFormatGenerationContext;

final class TemplateAwareCanonicalPlanAssembler extends CanonicalPlanAssembler
{
    public function assemble(PlanningRequest $request, GeneratedPlanDraft $draft): CanonicalPlan
    {
        $canonical = parent::assemble($request, $draft);
        $generated = $draft->toArray();
        $custom = is_array($generated['custom'] ?? null) ? $generated['custom'] : [];
        $context = app(PlanningFormatGenerationContext::class)->build($request);

        $allowed = [];
        $required = [];
        foreach ((array) ($context['custom_fields'] ?? []) as $field) {
            if (! is_array($field) || ($field['source'] ?? null) !== 'ai') {
                continue;
            }
            $key = (string) ($field['key'] ?? '');
            if ($key === '') {
                continue;
            }
            $allowed[$key] = true;
            if ((bool) ($field['required'] ?? false)) {
                $required[$key] = true;
            }
        }

        foreach ($required as $key => $_) {
            if (! array_key_exists($key, $custom)) {
                throw new AiContractException('GENERATED_FORMAT_CUSTOM_REQUIRED_MISSING', '$.custom.' . $key);
            }
        }
        foreach ($custom as $key => $_) {
            if (! isset($allowed[(string) $key])) {
                throw new AiContractException('GENERATED_FORMAT_CUSTOM_UNEXPECTED', '$.custom.' . (string) $key);
            }
        }

        if ($custom === []) {
            return $canonical;
        }

        $payload = $canonical->toArray();
        $payload['custom'] = $custom;

        return app(CanonicalPlanValidator::class)->validate($payload);
    }
}
