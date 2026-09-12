<?php

namespace App\Services\AI;

use App\Data\Planning\AdaptiveGeneratedPlan;
use App\Data\Planning\CanonicalPlan;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiContractException;
use App\Models\PlanningRequest;
use App\Services\Documents\PlanningFormatGenerationContext;

final class AdaptiveCanonicalPlanAssembler
{
    public function __construct(
        private CanonicalPlanValidator $canonicalValidator,
        private AdaptiveGeneratedPlanValidator $resultValidator,
        private PlanningFormatGenerationContext $formatContext,
    ) {}

    public function assemble(PlanningRequest $request, AdaptiveGeneratedPlan $result): CanonicalPlan
    {
        $request->loadMissing('currentInputVersion');
        if (! in_array($request->status, [
            PlanningRequestStatus::LISTA_PARA_PROCESAR,
            PlanningRequestStatus::GENERACION_IA,
            PlanningRequestStatus::CORRECCION_IA,
        ], true)
            || $request->commercial_authorized_at === null
            || ! $request->currentInputVersion) {
            throw new AiContractException('CANONICAL_REQUEST_NOT_READY');
        }

        $inputVersion = $request->currentInputVersion;
        if ($inputVersion->request_id !== $request->id || $inputVersion->snapshot !== $request->input_snapshot) {
            throw new AiContractException('CANONICAL_INPUT_SNAPSHOT_MISMATCH');
        }

        $snapshot = $inputVersion->snapshot;
        $curriculum = $snapshot['curriculum'] ?? null;
        if (! is_array($curriculum)) {
            throw new AiContractException('CANONICAL_CURRICULUM_SNAPSHOT_MISSING');
        }

        $formatContext = $this->formatContext->build($request);
        $result = $this->resultValidator->validate($result->toArray(), $formatContext);
        $core = $result->core();
        $coverage = $this->validatedCoverage($curriculum, (array) ($core['pda_coverage'] ?? []));
        $requestSnapshot = is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
        $profile = is_array($snapshot['group']['profile'] ?? null) ? $snapshot['group']['profile'] : [];
        $commercialSnapshot = is_array($request->calculation_snapshot) ? $request->calculation_snapshot : [];
        $formatVersionId = (int) ($formatContext['format_version_id'] ?? 0);
        if ($formatVersionId < 1) {
            throw new AiContractException('ADAPTIVE_CANONICAL_FORMAT_VERSION_REQUIRED');
        }

        $canonical = [
            'schema_version' => CanonicalPlanValidator::ADAPTIVE_SCHEMA_VERSION,
            'source' => [
                'planning_request_id' => (int) $request->id,
                'input_version_id' => (int) $inputVersion->id,
                'input_revision' => (int) $inputVersion->revision,
                'selection_revision' => (int) ($snapshot['selection_revision'] ?? $request->selection_revision),
                'curriculum_checksum' => (string) data_get($curriculum, 'version.checksum', ''),
                'commercial_snapshot_version' => isset($commercialSnapshot['schema_version']) ? (int) $commercialSnapshot['schema_version'] : null,
                'generation_contract_version' => FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
                'format_version_id' => $formatVersionId,
            ],
            'planning' => [
                'title' => (string) $core['title'],
                'project_name' => trim((string) ($requestSnapshot['project'] ?? '')) !== '' ? (string) $requestSnapshot['project'] : null,
                'topic' => $requestSnapshot['topic'] ?? null,
                'planning_type' => 'custom',
                'starts_on' => (string) ($requestSnapshot['starts_on'] ?? ''),
                'ends_on' => (string) ($requestSnapshot['ends_on'] ?? ''),
                'session_minutes' => isset($profile['session_minutes']) && (int) $profile['session_minutes'] > 0
                    ? (int) $profile['session_minutes']
                    : null,
            ],
            'context' => $this->buildContext($snapshot),
            'curricular_alignment' => $this->buildCurricularAlignment($curriculum, $coverage),
            'pedagogical_design' => [
                'purpose' => (string) $core['purpose'],
                'learning_goals' => array_values(array_map('strval', (array) $core['learning_goals'])),
                'assessment_strategy' => (string) $core['assessment_strategy'],
                'adaptation_considerations' => array_values(array_map('strval', (array) $core['adaptation_considerations'])),
            ],
        ];
        if ($result->fields() !== []) {
            $canonical['template_fields'] = $result->fields();
        }
        if ($result->custom() !== []) {
            $canonical['custom'] = $result->custom();
        }

        return $this->canonicalValidator->validate($canonical);
    }

    /** @param array<string,mixed> $curriculum @param list<mixed> $reported @return list<array{pda_code:string,explanation:string}> */
    private function validatedCoverage(array $curriculum, array $reported): array
    {
        $expected = [];
        foreach ((array) ($curriculum['pdas'] ?? []) as $pda) {
            if (! is_array($pda)) {
                continue;
            }
            $code = trim((string) ($pda['code'] ?? ''));
            if ($code !== '') {
                $expected[$code] = true;
            }
        }
        if ($expected === []) {
            throw new AiContractException('CANONICAL_SNAPSHOT_PDA_MISSING');
        }

        $coverage = [];
        foreach ($reported as $index => $row) {
            if (! is_array($row)) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_COVERAGE_INVALID', '$.core.pda_coverage[' . $index . ']');
            }
            $code = trim((string) ($row['pda_code'] ?? ''));
            $explanation = trim((string) ($row['explanation'] ?? ''));
            if (! isset($expected[$code])) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_NOT_ALLOWED', '$.core.pda_coverage[' . $index . '].pda_code', $code);
            }
            if ($explanation === '') {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_COVERAGE_INVALID', '$.core.pda_coverage[' . $index . '].explanation');
            }
            if (isset($coverage[$code])) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_DUPLICATE', '$.core.pda_coverage[' . $index . '].pda_code', $code);
            }
            $coverage[$code] = ['pda_code' => $code, 'explanation' => $explanation];
        }

        foreach (array_keys($expected) as $code) {
            if (! isset($coverage[$code])) {
                throw new AiContractException('ADAPTIVE_GENERATION_PDA_NOT_COVERED', '$.core.pda_coverage', $code);
            }
        }

        return array_values($coverage);
    }

    /** @param array<string,mixed> $curriculum @param list<array{pda_code:string,explanation:string}> $coverage @return array<string,mixed> */
    private function buildCurricularAlignment(array $curriculum, array $coverage): array
    {
        $grade = is_array($curriculum['grade'] ?? null) ? $curriculum['grade'] : [];
        $contentCodeById = [];
        foreach ((array) ($curriculum['contents'] ?? []) as $content) {
            if (is_array($content)) {
                $contentCodeById[(int) ($content['id'] ?? 0)] = (string) ($content['code'] ?? '');
            }
        }

        return [
            'curriculum' => [
                'code' => (string) data_get($curriculum, 'curriculum.code', ''),
                'name' => (string) data_get($curriculum, 'curriculum.name', ''),
                'version' => (string) (data_get($curriculum, 'version.label') ?? data_get($curriculum, 'version.number', '')),
                'checksum' => (string) data_get($curriculum, 'version.checksum', ''),
            ],
            'phase' => [
                'code' => (string) data_get($curriculum, 'phase.code', ''),
                'name' => (string) data_get($curriculum, 'phase.name', ''),
            ],
            'grade' => [
                'code' => (string) ($grade['code'] ?? ''),
                'name' => (string) ($grade['name'] ?? ''),
            ],
            'fields' => array_values(array_map(static fn (array $field): array => [
                'code' => (string) ($field['code'] ?? ''),
                'name' => (string) ($field['name'] ?? ''),
            ], array_filter((array) ($curriculum['formative_fields'] ?? []), 'is_array'))),
            'contents' => array_values(array_map(static fn (array $content): array => [
                'code' => (string) ($content['code'] ?? ''),
                'title' => (string) ($content['title'] ?? ''),
                'full_text' => (string) ($content['full_text'] ?? ''),
                'field_code' => (string) ($content['field_code'] ?? ''),
            ], array_filter((array) ($curriculum['contents'] ?? []), 'is_array'))),
            'pdas' => array_values(array_map(static fn (array $pda): array => [
                'code' => (string) ($pda['code'] ?? ''),
                'full_text' => (string) ($pda['full_text'] ?? ''),
                'content_code' => (string) ($contentCodeById[(int) ($pda['content_id'] ?? 0)] ?? ''),
                'grade_code' => (string) ($grade['code'] ?? ''),
            ], array_filter((array) ($curriculum['pdas'] ?? []), 'is_array'))),
            'articulating_axes' => array_values(array_map(static fn (array $axis): array => [
                'code' => (string) ($axis['code'] ?? ''),
                'name' => (string) ($axis['name'] ?? ''),
            ], array_filter((array) ($curriculum['axes'] ?? []), 'is_array'))),
            'coverage' => $coverage,
        ];
    }

    /** @param array<string,mixed> $snapshot @return array<string,mixed> */
    private function buildContext(array $snapshot): array
    {
        $group = is_array($snapshot['group'] ?? null) ? $snapshot['group'] : [];
        $profile = is_array($group['profile'] ?? null) ? $group['profile'] : [];
        $school = is_array($group['school'] ?? null) ? $group['school'] : [];

        return [
            'school_name' => $school['name'] ?? null,
            'school_type' => $school['school_type'] ?? null,
            'state' => $school['state'] ?? null,
            'municipality' => $school['municipality'] ?? null,
            'group_name' => $group['name'] ?? null,
            'school_year' => $group['school_year'] ?? null,
            'profile_revision' => $profile['revision'] ?? null,
            'student_count' => $profile['student_count'] ?? null,
            'general_level' => $profile['general_level'] ?? null,
            'characteristics' => $profile['characteristics'] ?? null,
            'difficulties' => $profile['difficulties'] ?? null,
            'educational_needs' => $profile['educational_needs'] ?? null,
            'available_materials' => $profile['available_materials'] ?? null,
            'teaching_preferences' => $profile['teaching_preferences'] ?? null,
            'preferred_activities' => $profile['preferred_activities'] ?? null,
            'restrictions' => $profile['restrictions'] ?? null,
            'management_observations' => $profile['management_observations'] ?? null,
            'required_structure' => $profile['required_structure'] ?? null,
            'preferred_assessment_tools' => $profile['preferred_assessment_tools'] ?? null,
            'additional_notes' => $profile['additional_notes'] ?? null,
            'special_events' => data_get($snapshot, 'request.special_events'),
            'teacher_comments' => data_get($snapshot, 'request.comments'),
            'pedagogical_notes' => data_get($snapshot, 'request.pedagogical_notes'),
            'required_activities' => data_get($snapshot, 'request.required_activities'),
            'book_pages' => data_get($snapshot, 'request.book_pages'),
        ];
    }
}
