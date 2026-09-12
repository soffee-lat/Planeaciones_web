<?php

namespace App\Services\AI;

use App\Data\AI\CorrectionResult;
use App\Data\Planning\CanonicalPlan;
use App\Data\Planning\GeneratedPlanDraft;
use App\Exceptions\AiContractException;
use App\Models\PlanningRequest;
use App\Services\Documents\PlanningFormatGenerationContext;
use App\Support\AI\CanonicalJson;

final class CanonicalPlanCorrectionApplier
{
    /** @var list<string> */
    private const MUTABLE_ROOTS = ['planning', 'pedagogical_design', 'sessions', 'assessment_plan', 'resources', 'adaptation_notes'];

    /** @var list<string> */
    private const ADAPTIVE_MUTABLE_ROOTS = ['planning', 'pedagogical_design', 'template_fields', 'custom'];

    public function __construct(
        private CanonicalPlanValidator $canonicalValidator,
        private GeneratedPlanDraftValidator $draftValidator,
        private CanonicalPlanAssembler $assembler,
        private PlanningFormatGenerationContext $formatContext,
    ) {}

    /** @param list<string> $sectionKeys */
    public function apply(
        PlanningRequest $request,
        CanonicalPlan $source,
        CorrectionResult $result,
        array $sectionKeys,
    ): CanonicalPlan {
        $sourcePayload = $this->canonicalValidator->validate($source->toArray())->toArray();
        if (($sourcePayload['schema_version'] ?? null) === CanonicalPlanValidator::ADAPTIVE_SCHEMA_VERSION) {
            return $this->applyAdaptive($request, $sourcePayload, $result, $sectionKeys);
        }

        return $this->applyLegacy($request, $source, $sourcePayload, $result, $sectionKeys);
    }

    /** @param array<string,mixed> $sourcePayload @param list<string> $sectionKeys */
    private function applyAdaptive(
        PlanningRequest $request,
        array $sourcePayload,
        CorrectionResult $result,
        array $sectionKeys,
    ): CanonicalPlan {
        $allowed = array_values(array_unique(array_map('strval', $sectionKeys)));
        $format = $this->formatContext->build($request);
        $allowedCustom = [];
        foreach ((array) ($format['custom_fields'] ?? []) as $field) {
            if (is_array($field) && trim((string) ($field['key'] ?? '')) !== '') {
                $allowedCustom[(string) $field['key']] = true;
            }
        }
        $allowedTemplateFields = [];
        foreach ((array) ($format['ai_standard_fields'] ?? []) as $field) {
            if (is_array($field) && trim((string) ($field['path'] ?? '')) !== '') {
                $allowedTemplateFields[(string) $field['path']] = true;
            }
        }

        $before = $sourcePayload;
        foreach ($result->patch as $root => $replacement) {
            if (! in_array($root, self::ADAPTIVE_MUTABLE_ROOTS, true) || ! in_array($root, $allowed, true)) {
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

            if ($root === 'custom') {
                if (! is_array($replacement) || array_is_list($replacement)) {
                    throw new AiContractException('AI_CORRECTION_ADAPTIVE_PATCH_INVALID', '$.patch.custom');
                }
                foreach (array_keys($replacement) as $key) {
                    if (! isset($allowedCustom[(string) $key])) {
                        throw new AiContractException('AI_CORRECTION_CUSTOM_FIELD_NOT_ALLOWED', '$.patch.custom.' . (string) $key);
                    }
                }
                $existingCustom = is_array($sourcePayload['custom'] ?? null) ? $sourcePayload['custom'] : [];
                $sourcePayload['custom'] = [...$existingCustom, ...$replacement];
                continue;
            }

            if ($root === 'template_fields') {
                if (! is_array($replacement) || array_is_list($replacement)) {
                    throw new AiContractException('AI_CORRECTION_ADAPTIVE_PATCH_INVALID', '$.patch.template_fields');
                }
                foreach (array_keys($replacement) as $path) {
                    if (! isset($allowedTemplateFields[(string) $path])) {
                        throw new AiContractException('AI_CORRECTION_TEMPLATE_FIELD_NOT_ALLOWED', '$.patch.template_fields.' . (string) $path);
                    }
                }
                $existingFields = is_array($sourcePayload['template_fields'] ?? null) ? $sourcePayload['template_fields'] : [];
                $sourcePayload['template_fields'] = [...$existingFields, ...$replacement];
                continue;
            }

            if (! is_array($replacement) || array_is_list($replacement)) {
                throw new AiContractException('AI_CORRECTION_ADAPTIVE_PATCH_INVALID', '$.patch.' . $root);
            }
            $sourcePayload[$root] = $replacement;
        }

        $corrected = $this->canonicalValidator->validate($sourcePayload);
        $this->assertAdaptiveUnchangedOutsideScope($before, $corrected->toArray(), $allowed);

        return $corrected;
    }

    /** @param list<string> $sectionKeys @param array<string,mixed> $sourcePayload */
    private function applyLegacy(
        PlanningRequest $request,
        CanonicalPlan $source,
        array $sourcePayload,
        CorrectionResult $result,
        array $sectionKeys,
    ): CanonicalPlan {
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
        if (isset($sourcePayload['custom'])) {
            $draftPayload['custom'] = $sourcePayload['custom'];
        }

        $draft = $this->draftValidator->validate($draftPayload);
        if (! $draft instanceof GeneratedPlanDraft) {
            throw new AiContractException('AI_CORRECTION_LEGACY_DRAFT_EXPECTED');
        }
        $corrected = $this->assembler->assemble($request, $draft);
        $this->assertUnchangedOutsideScope($source->toArray(), $corrected->toArray(), $allowed);

        return $corrected;
    }

    /** @param array<string,mixed> $before @param array<string,mixed> $after @param list<string> $allowed */
    private function assertAdaptiveUnchangedOutsideScope(array $before, array $after, array $allowed): void
    {
        foreach (['source', 'context', 'curricular_alignment'] as $immutable) {
            if (CanonicalJson::hash($before[$immutable]) !== CanonicalJson::hash($after[$immutable])) {
                throw new AiContractException('AI_CORRECTION_IMMUTABLE_SECTION_CHANGED', '$.' . $immutable);
            }
        }

        foreach (['pedagogical_design', 'template_fields', 'custom'] as $root) {
            $beforeValue = $before[$root] ?? [];
            $afterValue = $after[$root] ?? [];
            if (! in_array($root, $allowed, true)
                && CanonicalJson::hash($beforeValue) !== CanonicalJson::hash($afterValue)) {
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
