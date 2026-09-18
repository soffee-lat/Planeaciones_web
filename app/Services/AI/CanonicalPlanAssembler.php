<?php

namespace App\Services\AI;

use App\Data\Planning\CanonicalPlan;
use App\Data\Planning\GeneratedPlanDraft;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiContractException;
use App\Models\PlanningRequest;
use Carbon\CarbonImmutable;

class CanonicalPlanAssembler
{
    public function __construct(
        private CanonicalPlanValidator $canonicalValidator,
        private GeneratedPlanDraftValidator $draftValidator,
    ) {}

    public function assemble(PlanningRequest $request, GeneratedPlanDraft $draft): CanonicalPlan
    {
        // No confiar en que el caller haya construido el DTO mediante el validador.
        $draft = $this->draftValidator->validate($draft->toArray());
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
        $generated = $draft->toArray();
        $curriculum = $snapshot['curriculum'] ?? null;
        if (! is_array($curriculum)) {
            throw new AiContractException('CANONICAL_CURRICULUM_SNAPSHOT_MISSING');
        }

        $alignment = $this->buildCurricularAlignment($curriculum, $generated['sessions']);
        $this->validateSessionDates($snapshot['request'] ?? [], $generated['sessions']);
        $this->validateSessionsAgainstSchedule(
            is_array($snapshot['group']['planning_calendar'] ?? null) ? $snapshot['group']['planning_calendar'] : [],
            $generated['sessions'],
        );

        $profile = is_array($snapshot['group']['profile'] ?? null) ? $snapshot['group']['profile'] : [];
        $sessionMinutes = (int) ($profile['session_minutes'] ?? 0);
        if ($sessionMinutes <= 0) {
            throw new AiContractException('CANONICAL_SESSION_MINUTES_MISSING');
        }

        $requestSnapshot = is_array($snapshot['request'] ?? null) ? $snapshot['request'] : [];
        $commercialSnapshot = is_array($request->calculation_snapshot) ? $request->calculation_snapshot : [];

        $canonical = [
            'schema_version' => CanonicalPlanValidator::SCHEMA_VERSION,
            'source' => [
                'planning_request_id' => $request->id,
                'input_version_id' => $inputVersion->id,
                'input_revision' => (int) $inputVersion->revision,
                'selection_revision' => (int) ($snapshot['selection_revision'] ?? $request->selection_revision),
                'curriculum_checksum' => (string) ($curriculum['version']['checksum'] ?? ''),
                'commercial_snapshot_version' => isset($commercialSnapshot['schema_version']) ? (int) $commercialSnapshot['schema_version'] : null,
                'generation_contract_version' => GeneratedPlanDraftValidator::CONTRACT_VERSION,
            ],
            'planning' => [
                'title' => $generated['title'],
                'project_name' => trim((string) ($requestSnapshot['project'] ?? '')) !== '' ? $requestSnapshot['project'] : ($generated['project_name'] ?? null),
                'topic' => $requestSnapshot['topic'] ?? null,
                'planning_type' => $this->planningType($requestSnapshot),
                'starts_on' => (string) ($requestSnapshot['starts_on'] ?? ''),
                'ends_on' => (string) ($requestSnapshot['ends_on'] ?? ''),
                'session_minutes' => $sessionMinutes,
                'session_count' => count($generated['sessions']),
            ],
            'context' => [
                ...$this->buildContext($snapshot),
                'schedule_revision' => $snapshot['group']['schedule']['revision'] ?? null,
                'planning_calendar' => $snapshot['group']['planning_calendar'] ?? [],
            ],
            'curricular_alignment' => $alignment,
            'pedagogical_design' => [
                'purpose' => $generated['purpose'],
                'problem_or_interest' => $generated['problem_or_interest'] ?? null,
                'scenario' => $generated['scenario'] ?? null,
                'learning_goals' => $generated['learning_goals'],
                'methodology' => $generated['methodology'],
                'transversal_connections' => $generated['transversal_connections'],
            ],
            'sessions' => $generated['sessions'],
            'assessment_plan' => $generated['assessment_plan'],
            'resources' => [
                'physical_materials' => $generated['resources']['physical_materials'],
                'digital_resources' => $generated['resources']['digital_resources'],
                'provided_references' => $this->trustedProvidedReferences($requestSnapshot),
            ],
            'adaptation_notes' => $generated['adaptation_notes'],
            ...(isset($generated['custom']) ? ['custom' => $generated['custom']] : []),
        ];

        return $this->canonicalValidator->validate($canonical);
    }

    /**
     * @param array<string,mixed> $curriculum
     * @param list<array<string,mixed>> $sessions
     * @return array<string,mixed>
     */
    private function buildCurricularAlignment(array $curriculum, array $sessions): array
    {
        $fields = array_values($curriculum['formative_fields'] ?? []);
        $contents = array_values($curriculum['contents'] ?? []);
        $pdas = array_values($curriculum['pdas'] ?? []);
        $axes = array_values($curriculum['axes'] ?? []);
        $grade = $curriculum['grade'] ?? [];

        $fieldByCode = [];
        foreach ($fields as $field) {
            $fieldByCode[(string) $field['code']] = $field;
        }

        $contentByCode = [];
        $contentCodeById = [];
        foreach ($contents as $content) {
            $code = (string) $content['code'];
            $contentByCode[$code] = $content;
            $contentCodeById[(int) $content['id']] = $code;
        }

        $pdaByCode = [];
        foreach ($pdas as $pda) {
            $contentCode = $contentCodeById[(int) ($pda['content_id'] ?? 0)] ?? null;
            if ($contentCode === null || (int) ($pda['grade_id'] ?? 0) !== (int) ($grade['id'] ?? -1)) {
                throw new AiContractException('CANONICAL_SNAPSHOT_PDA_INVALID', '$.curriculum.pdas', (string) ($pda['code'] ?? 'unknown'));
            }
            $pdaByCode[(string) $pda['code']] = [
                ...$pda,
                'content_code' => $contentCode,
            ];
        }

        $axisByCode = [];
        foreach ($axes as $axis) {
            $axisByCode[(string) $axis['code']] = $axis;
        }

        $coverage = [];
        foreach ($pdaByCode as $code => $_) {
            $coverage[$code] = [];
        }

        foreach ($sessions as $index => $session) {
            $path = '$.sessions[' . $index . ']';
            $this->assertCodesAllowed($session['field_codes'], $fieldByCode, 'GENERATED_FIELD_REFERENCE_NOT_ALLOWED', $path . '.field_codes');
            $this->assertCodesAllowed($session['content_codes'], $contentByCode, 'GENERATED_CONTENT_REFERENCE_NOT_ALLOWED', $path . '.content_codes');
            $this->assertCodesAllowed($session['pda_codes'], $pdaByCode, 'GENERATED_PDA_REFERENCE_NOT_ALLOWED', $path . '.pda_codes');
            $this->assertCodesAllowed($session['axis_codes'], $axisByCode, 'GENERATED_AXIS_REFERENCE_NOT_ALLOWED', $path . '.axis_codes');

            foreach ($session['content_codes'] as $contentCode) {
                $fieldCode = (string) ($contentByCode[$contentCode]['field_code'] ?? '');
                if ($fieldCode === '' || ! in_array($fieldCode, $session['field_codes'], true)) {
                    throw new AiContractException('GENERATED_CONTENT_FIELD_MISMATCH', $path . '.content_codes', $contentCode);
                }
            }

            foreach ($session['pda_codes'] as $pdaCode) {
                $contentCode = $pdaByCode[$pdaCode]['content_code'];
                if (! in_array($contentCode, $session['content_codes'], true)) {
                    throw new AiContractException('GENERATED_PDA_CONTENT_MISMATCH', $path . '.pda_codes', $pdaCode);
                }
                $coverage[$pdaCode][] = $session['id'];
            }
        }

        $coverageRows = [];
        foreach ($coverage as $pdaCode => $sessionIds) {
            if ($sessionIds === []) {
                throw new AiContractException('GENERATED_PDA_NOT_COVERED', '$.sessions', $pdaCode);
            }
            $coverageRows[] = [
                'pda_code' => $pdaCode,
                'session_ids' => array_values(array_unique($sessionIds)),
            ];
        }

        return [
            'curriculum' => [
                'code' => (string) ($curriculum['curriculum']['code'] ?? ''),
                'name' => (string) ($curriculum['curriculum']['name'] ?? ''),
                'version' => (string) ($curriculum['version']['label'] ?? $curriculum['version']['number'] ?? ''),
                'checksum' => (string) ($curriculum['version']['checksum'] ?? ''),
            ],
            'phase' => [
                'code' => (string) ($curriculum['phase']['code'] ?? ''),
                'name' => (string) ($curriculum['phase']['name'] ?? ''),
            ],
            'grade' => [
                'code' => (string) ($grade['code'] ?? ''),
                'name' => (string) ($grade['name'] ?? ''),
            ],
            'fields' => array_map(fn (array $field) => [
                'code' => (string) $field['code'],
                'name' => (string) $field['name'],
            ], $fields),
            'contents' => array_map(fn (array $content) => [
                'code' => (string) $content['code'],
                'title' => (string) $content['title'],
                'full_text' => (string) $content['full_text'],
                'field_code' => (string) $content['field_code'],
            ], $contents),
            'pdas' => array_map(fn (array $pda) => [
                'code' => (string) $pda['code'],
                'full_text' => (string) $pda['full_text'],
                'content_code' => (string) $pda['content_code'],
                'grade_code' => (string) ($grade['code'] ?? ''),
            ], array_values($pdaByCode)),
            'articulating_axes' => array_map(fn (array $axis) => [
                'code' => (string) $axis['code'],
                'name' => (string) $axis['name'],
            ], $axes),
            'coverage' => $coverageRows,
        ];
    }

    /** @param list<string> $codes @param array<string,mixed> $allowed */
    private function assertCodesAllowed(array $codes, array $allowed, string $errorCode, string $path): void
    {
        foreach ($codes as $code) {
            if (! array_key_exists($code, $allowed)) {
                throw new AiContractException($errorCode, $path, $code);
            }
        }
    }

    /** @param array<string,mixed> $requestSnapshot @param list<array<string,mixed>> $sessions */
    private function validateSessionDates(array $requestSnapshot, array $sessions): void
    {
        $starts = (string) ($requestSnapshot['starts_on'] ?? '');
        $ends = (string) ($requestSnapshot['ends_on'] ?? '');
        if ($starts === '' || $ends === '') {
            throw new AiContractException('CANONICAL_REQUEST_DATES_MISSING');
        }
        $startsOn = CarbonImmutable::parse($starts);
        $endsOn = CarbonImmutable::parse($ends);

        foreach ($sessions as $index => $session) {
            if (($session['date'] ?? null) === null) {
                continue;
            }
            $date = CarbonImmutable::parse($session['date']);
            if ($date->lt($startsOn) || $date->gt($endsOn)) {
                throw new AiContractException('GENERATED_SESSION_DATE_OUTSIDE_REQUEST', '$.sessions[' . $index . '].date');
            }
        }
    }

    /**
     * @param list<array<string,mixed>> $calendar
     * @param list<array<string,mixed>> $sessions
     */
    private function validateSessionsAgainstSchedule(array $calendar, array $sessions): void
    {
        // Compatibilidad con solicitudes antiguas confirmadas antes de existir
        // el módulo de horario.
        if ($calendar === []) {
            return;
        }

        $availableByDate = [];
        foreach ($calendar as $day) {
            $date = (string) ($day['date'] ?? '');
            if ($date === '') {
                continue;
            }

            $availableByDate[$date] = array_values(array_filter(
                is_array($day['blocks'] ?? null) ? $day['blocks'] : [],
                fn (array $block): bool => (bool) ($block['include_in_planning'] ?? false),
            ));
        }

        $sessionsByDate = [];
        foreach ($sessions as $index => $session) {
            $date = (string) ($session['date'] ?? '');
            if ($date === '' || ! array_key_exists($date, $availableByDate)) {
                throw new AiContractException(
                    'GENERATED_SESSION_DATE_NOT_IN_SCHEDULE',
                    '$.sessions[' . $index . '].date',
                    $date === '' ? 'null' : $date,
                );
            }
            $sessionsByDate[$date][] = ['index' => $index, 'session' => $session];
        }

        foreach ($availableByDate as $date => $blocks) {
            $daySessions = $sessionsByDate[$date] ?? [];
            if (count($daySessions) !== count($blocks)) {
                throw new AiContractException(
                    'GENERATED_SCHEDULE_BLOCK_COUNT_MISMATCH',
                    '$.sessions',
                    $date . ':expected=' . count($blocks) . ':actual=' . count($daySessions),
                );
            }

            foreach ($daySessions as $position => $entry) {
                $session = $entry['session'];
                $block = $blocks[$position];
                $path = '$.sessions[' . $entry['index'] . ']';

                $expectedMinutes = (int) ($block['minutes'] ?? 0);
                if ($expectedMinutes < 1 || (int) ($session['estimated_minutes'] ?? 0) !== $expectedMinutes) {
                    throw new AiContractException(
                        'GENERATED_SESSION_DURATION_NOT_IN_SCHEDULE',
                        $path . '.estimated_minutes',
                        (string) $expectedMinutes,
                    );
                }

                $blockFields = array_values(array_filter((array) ($block['field_codes'] ?? []), 'is_string'));
                if ($blockFields !== [] && ! (bool) ($block['is_flexible'] ?? false)) {
                    $sessionFields = array_values(array_filter((array) ($session['field_codes'] ?? []), 'is_string'));
                    if (array_intersect($blockFields, $sessionFields) === []) {
                        throw new AiContractException(
                            'GENERATED_SESSION_FIELD_NOT_ALLOWED_BY_SCHEDULE',
                            $path . '.field_codes',
                            implode(',', $blockFields),
                        );
                    }
                }
            }
        }
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
            'special_events' => $snapshot['request']['special_events'] ?? null,
            'teacher_comments' => $snapshot['request']['comments'] ?? null,
            'pedagogical_notes' => $snapshot['request']['pedagogical_notes'] ?? null,
            'required_activities' => $snapshot['request']['required_activities'] ?? null,
            'book_pages' => $snapshot['request']['book_pages'] ?? null,
        ];
    }

    /** @param array<string,mixed> $requestSnapshot @return list<string> */
    private function trustedProvidedReferences(array $requestSnapshot): array
    {
        $references = [];
        $bookPages = trim((string) ($requestSnapshot['book_pages'] ?? ''));
        if ($bookPages !== '') {
            $references[] = $bookPages;
        }

        return $references;
    }

    /** @param array<string,mixed> $requestSnapshot */
    private function planningType(array $requestSnapshot): string
    {
        return trim((string) ($requestSnapshot['project'] ?? '')) !== '' ? 'project' : 'didactic_sequence';
    }
}
