<?php

namespace App\Services\AI;

use App\Data\AI\CorrectionResult;
use App\Data\Planning\CanonicalPlan;
use App\Exceptions\AiContractException;
use App\Models\PlanningRequest;
use App\Support\AI\CanonicalJson;

final class CanonicalPlanCorrectionApplier
{
    /** @var list<string> */
    private const MUTABLE_ROOTS = ['planning', 'pedagogical_design', 'sessions', 'assessment_plan', 'resources', 'adaptation_notes'];

    public function __construct(
        private CanonicalPlanValidator $canonicalValidator,
        private GeneratedPlanDraftValidator $draftValidator,
        private CanonicalPlanAssembler $assembler,
    ) {}

    /** @param list<string> $sectionKeys */
    public function apply(
        PlanningRequest $request,
        CanonicalPlan $source,
        CorrectionResult $result,
        array $sectionKeys,
    ): CanonicalPlan {
        $sourcePayload = $this->canonicalValidator->validate($source->toArray())->toArray();
        $allowed = array_values(array_unique(array_map('strval', $sectionKeys)));

        foreach ($result->patch as $root => $replacement) {
            if (! in_array($root, self::MUTABLE_ROOTS, true) || ! in_array($root, $allowed, true)) {
                throw new AiContractException('AI_CORRECTION_PATCH_OUTSIDE_SCOPE', '$.patch.' . $root);
            }

            if ($root === 'planning') {
                if (! is_array($replacement) || array_is_list($replacement)) {
                    throw new AiContractException('AI_CORRECTION_PLANNING_PATCH_INVALID', '$.patch.planning');
                }
                foreach (array_keys($replacement) as $field) {
                    if (! in_array($field, ['title', 'project_name'], true)) {
                        throw new AiContractException('AI_CORRECTION_PLANNING_FIELD_IMMUTABLE', '$.patch.planning.' . $field);
                    }
                }
                $sourcePayload['planning'] = [...$sourcePayload['planning'], ...$replacement];
                continue;
            }

            $sourcePayload[$root] = $replacement;
        }

        $draftPayload = [
            'contract_version' => GeneratedPlanDraftValidator::CONTRACT_VERSION,
            'title' => $sourcePayload['planning']['title'],
            'project_name' => $sourcePayload['planning']['project_name'] ?? null,
            'purpose' => $sourcePayload['pedagogical_design']['purpose'],
            'problem_or_interest' => $sourcePayload['pedagogical_design']['problem_or_interest'] ?? null,
            'scenario' => $sourcePayload['pedagogical_design']['scenario'] ?? null,
            'learning_goals' => $sourcePayload['pedagogical_design']['learning_goals'],
            'methodology' => $sourcePayload['pedagogical_design']['methodology'],
            'transversal_connections' => $sourcePayload['pedagogical_design']['transversal_connections'],
            'sessions' => $sourcePayload['sessions'],
            'assessment_plan' => $sourcePayload['assessment_plan'],
            'resources' => $sourcePayload['resources'],
            'adaptation_notes' => $sourcePayload['adaptation_notes'],
        ];

        $draft = $this->draftValidator->validate($draftPayload);
        $corrected = $this->assembler->assemble($request, $draft);
        $this->assertUnchangedOutsideScope($source->toArray(), $corrected->toArray(), $allowed);

        return $corrected;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @param list<string> $allowed */
    private function assertUnchangedOutsideScope(array $before, array $after, array $allowed): void
    {
        foreach (['source', 'context', 'curricular_alignment'] as $immutable) {
            if (CanonicalJson::hash($before[$immutable]) !== CanonicalJson::hash($after[$immutable])) {
                throw new AiContractException('AI_CORRECTION_IMMUTABLE_SECTION_CHANGED', '$.' . $immutable);
            }
        }

        foreach (array_slice(self::MUTABLE_ROOTS, 1) as $root) {
            if (! in_array($root, $allowed, true)
                && CanonicalJson::hash($before[$root]) !== CanonicalJson::hash($after[$root])) {
                throw new AiContractException('AI_CORRECTION_SECTION_CHANGED_OUTSIDE_SCOPE', '$.' . $root);
            }
        }

        foreach (['topic', 'planning_type', 'starts_on', 'ends_on', 'session_minutes'] as $field) {
            if (($before['planning'][$field] ?? null) !== ($after['planning'][$field] ?? null)) {
                throw new AiContractException('AI_CORRECTION_PLANNING_SERVER_FIELD_CHANGED', '$.planning.' . $field);
            }
        }
        if (! in_array('planning', $allowed, true)) {
            foreach (['title', 'project_name'] as $field) {
                if (($before['planning'][$field] ?? null) !== ($after['planning'][$field] ?? null)) {
                    throw new AiContractException('AI_CORRECTION_PLANNING_CHANGED_OUTSIDE_SCOPE', '$.planning.' . $field);
                }
            }
        }
    }
}
