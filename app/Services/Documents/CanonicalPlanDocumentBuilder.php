<?php

namespace App\Services\Documents;

final class CanonicalPlanDocumentBuilder
{
    /**
     * Construye un view-model documental orientado a consulta docente.
     * La salida prioriza tablas, fechas, horario, secuencia didáctica y
     * evaluación. Los códigos técnicos del catálogo permanecen en el canonical
     * para trazabilidad, pero no se imprimen en el documento del docente.
     *
     * @param array<string,mixed> $plan
     * @return list<array<string,mixed>>
     */
    public function build(array $plan): array
    {
        $blocks = [];
        $planning = $this->object($plan['planning'] ?? []);
        $alignment = $this->object($plan['curricular_alignment'] ?? []);
        $pedagogy = $this->object($plan['pedagogical_design'] ?? []);
        $assessment = $this->object($plan['assessment_plan'] ?? []);
        $resources = $this->object($plan['resources'] ?? []);
        $adaptation = $this->object($plan['adaptation_notes'] ?? []);
        $context = $this->object($plan['context'] ?? []);
        $sessions = $this->list($plan['sessions'] ?? []);
        $sessionSlots = $this->sessionScheduleSlots($sessions, $context);

        $title = trim((string) ($planning['title'] ?? 'Planeación didáctica')) ?: 'Planeación didáctica';
        $project = trim((string) ($planning['project_name'] ?? ''));
        $topic = trim((string) ($planning['topic'] ?? ''));

        $blocks[] = ['type' => 'title', 'text' => $title];
        if ($project !== '' || $topic !== '') {
            $blocks[] = ['type' => 'subtitle', 'text' => $project !== '' ? $project : $topic];
        }

        $grade = $this->object($alignment['grade'] ?? []);
        $phase = $this->object($alignment['phase'] ?? []);
        $metaRows = [
            [
                'Escuela', $this->value($context['school_name'] ?? null, '—'),
                'Grupo', $this->value($context['group_name'] ?? null, '—'),
            ],
            [
                'Periodo', $this->period($planning),
                'Bloques planeados', $this->sessionSummary($planning),
            ],
            [
                'Nivel educativo', $this->value($context['educational_level_label'] ?? null, '—'),
                'Ciclo escolar', $this->value($context['school_year'] ?? null, '—'),
            ],
            [
                'Grado', $this->value($grade['name'] ?? null, '—'),
                'Fase', $this->value($phase['name'] ?? null, '—'),
            ],
        ];
        $blocks[] = ['type' => 'meta_table', 'rows' => $metaRows];

        $blocks[] = ['type' => 'section', 'text' => 'Propósito y enfoque pedagógico'];
        $methodology = $this->object($pedagogy['methodology'] ?? []);
        $focusRows = [
            ['Propósito', $this->value($pedagogy['purpose'] ?? null, 'No especificado.')],
        ];
        if ($this->value($pedagogy['problem_or_interest'] ?? null) !== '') {
            $focusRows[] = ['Problemática o interés', $this->value($pedagogy['problem_or_interest'] ?? null)];
        }
        if ($this->value($pedagogy['scenario'] ?? null) !== '') {
            $focusRows[] = ['Escenario', $this->value($pedagogy['scenario'] ?? null)];
        }
        if ($this->value($methodology['name'] ?? null) !== '') {
            $focusRows[] = ['Metodología', $this->value($methodology['name'] ?? null)];
        }
        $methodologyPhases = $this->stringList($methodology['phases'] ?? []);
        if ($methodologyPhases !== []) {
            $focusRows[] = ['Fases metodológicas', implode(' → ', $methodologyPhases)];
        }
        if ($this->value($methodology['rationale'] ?? null) !== '') {
            $focusRows[] = ['Justificación metodológica', $this->value($methodology['rationale'] ?? null)];
        }
        $blocks[] = ['type' => 'two_column_table', 'rows' => $focusRows];

        $goals = $this->stringList($pedagogy['learning_goals'] ?? []);
        if ($goals !== []) {
            $blocks[] = ['type' => 'list', 'label' => 'Metas de aprendizaje', 'items' => $goals];
        }

        $curriculumRows = [];
        $fields = $this->namedList($alignment['fields'] ?? []);
        $axes = $this->namedList($alignment['articulating_axes'] ?? []);
        if ($fields !== '') {
            $curriculumRows[] = ['Campos formativos', $fields];
        }
        if ($axes !== '') {
            $curriculumRows[] = ['Ejes articuladores', $axes];
        }

        $contents = [];
        foreach ($this->list($alignment['contents'] ?? []) as $contentRaw) {
            $content = $this->object($contentRaw);
            $text = trim((string) ($content['full_text'] ?? $content['title'] ?? ''));
            if ($text !== '') {
                $contents[] = $text;
            }
        }
        if ($contents !== []) {
            $curriculumRows[] = ['Contenidos pertinentes', implode("\n", $contents)];
        }

        $pdas = [];
        foreach ($this->list($alignment['pdas'] ?? []) as $pdaRaw) {
            $pda = $this->object($pdaRaw);
            $text = trim((string) ($pda['full_text'] ?? ''));
            if ($text !== '') {
                $pdas[] = $text;
            }
        }
        if ($pdas !== []) {
            $curriculumRows[] = ['Procesos de desarrollo de aprendizaje', implode("\n", $pdas)];
        }

        if ($curriculumRows !== []) {
            $blocks[] = ['type' => 'section', 'text' => 'Referentes curriculares pertinentes'];
            $blocks[] = ['type' => 'two_column_table', 'rows' => $curriculumRows];
        }

        $connections = [];
        foreach ($this->list($pedagogy['transversal_connections'] ?? []) as $connectionRaw) {
            $connection = $this->object($connectionRaw);
            $description = trim((string) ($connection['description'] ?? ''));
            if ($description !== '') {
                $connections[] = $description;
            }
        }
        if ($connections !== []) {
            $blocks[] = ['type' => 'list', 'label' => 'Conexiones transversales', 'items' => $connections];
        }

        $sessionFriendlyLabels = [];
        foreach ($sessions as $index => $sessionRaw) {
            $session = $this->object($sessionRaw);
            $slot = $sessionSlots[$index] ?? [];
            $sessionId = trim((string) ($session['id'] ?? ''));
            if ($sessionId !== '') {
                $sessionFriendlyLabels[$sessionId] = $this->friendlySessionLabel($session, $slot);
            }
        }

        if ($sessions !== []) {
            $blocks[] = ['type' => 'section', 'text' => 'Vista semanal'];
            $overviewRows = [];
            foreach ($sessions as $index => $sessionRaw) {
                $session = $this->object($sessionRaw);
                $slot = $sessionSlots[$index] ?? [];
                $evidence = '';
                foreach ($this->list($session['moments'] ?? []) as $momentRaw) {
                    $moment = $this->object($momentRaw);
                    foreach ($this->list($moment['activities'] ?? []) as $activityRaw) {
                        $activity = $this->object($activityRaw);
                        $candidate = implode('; ', $this->stringList($activity['expected_evidence'] ?? []));
                        if ($candidate !== '') {
                            $evidence = $candidate;
                            break 2;
                        }
                    }
                }

                $date = $this->value($session['date'] ?? null, 'Por definir');
                $time = $this->slotTime($slot);
                $overviewRows[] = [
                    trim($date . ($time !== '' ? "\n" . $time : '')),
                    $this->slotName($slot, $session),
                    $this->value($session['title'] ?? null, 'Actividad'),
                    $this->value($session['specific_goal'] ?? null, '—'),
                    isset($session['estimated_minutes']) ? ((int) $session['estimated_minutes']) . ' min' : '—',
                    $evidence !== '' ? $evidence : '—',
                ];
            }
            $blocks[] = [
                'type' => 'table',
                'headers' => ['Fecha y horario', 'Materia / bloque', 'Tema o actividad', 'Propósito', 'Tiempo', 'Evidencia'],
                'rows' => $overviewRows,
            ];
        }

        foreach ($sessions as $index => $sessionRaw) {
            $session = $this->object($sessionRaw);
            $slot = $sessionSlots[$index] ?? [];
            if ($index > 0) {
                $blocks[] = ['type' => 'page_break'];
            }

            $sessionTitle = $this->value($session['title'] ?? null, 'Actividad');
            $date = $this->value($session['date'] ?? null, 'Fecha por definir');
            $minutes = isset($session['estimated_minutes']) ? (int) $session['estimated_minutes'] : null;
            $slotName = $this->slotName($slot, $session);
            $time = $this->slotTime($slot);
            $headerText = $date . ' · ' . $slotName;
            $headerMeta = trim(($time !== '' ? $time : '')
                . ($minutes ? (($time !== '' ? ' · ' : '') . $minutes . ' min') : ''));

            $blocks[] = [
                'type' => 'session_header',
                'text' => $headerText,
                'meta' => $headerMeta,
            ];

            $summaryRows = [
                ['Tema / actividad', $sessionTitle],
                ['Propósito del bloque', $this->value($session['specific_goal'] ?? null, 'No especificado.')],
            ];
            if ($this->value($session['methodology_phase'] ?? null) !== '') {
                $summaryRows[] = ['Fase metodológica', $this->value($session['methodology_phase'] ?? null)];
            }
            $blocks[] = ['type' => 'two_column_table', 'rows' => $summaryRows];

            $activityRows = [];
            foreach ($this->list($session['moments'] ?? []) as $momentRaw) {
                $moment = $this->object($momentRaw);
                $momentType = ucfirst((string) ($moment['type'] ?? 'Momento'));
                $momentMinutes = isset($moment['minutes']) ? (int) $moment['minutes'] : null;

                foreach ($this->list($moment['activities'] ?? []) as $activityRaw) {
                    $activity = $this->object($activityRaw);
                    $activityText = $this->value($activity['instruction'] ?? null, 'Actividad');
                    $organization = $this->organizationLabel((string) ($activity['organization'] ?? ''));
                    $materials = $this->stringList($activity['materials'] ?? []);
                    if ($organization !== '') {
                        $activityText .= "\nOrganización: " . $organization;
                    }
                    if ($materials !== []) {
                        $activityText .= "\nRecursos: " . implode(', ', $materials);
                    }

                    $checks = $this->stringList($activity['assessment_checks'] ?? []);
                    $evidence = $this->stringList($activity['expected_evidence'] ?? []);
                    $evaluationText = $checks !== [] ? 'Criterios: ' . implode('; ', $checks) : '—';
                    if ($evidence !== []) {
                        $evaluationText .= "\nEvidencia: " . implode('; ', $evidence);
                    }

                    $activityRows[] = [
                        $momentType,
                        $momentMinutes ? $momentMinutes . ' min' : '—',
                        $activityText,
                        $this->value($activity['teacher_action'] ?? null, '—'),
                        $this->value($activity['student_action'] ?? null, '—'),
                        $evaluationText,
                    ];
                }
            }

            if ($activityRows !== []) {
                $blocks[] = ['type' => 'section', 'text' => 'Secuencia didáctica'];
                $blocks[] = [
                    'type' => 'table',
                    'headers' => ['Momento', 'Tiempo', 'Actividad y recursos', 'Docente', 'Alumnos', 'Evaluación / evidencia'],
                    'rows' => $activityRows,
                ];
            }

            $formative = $this->object($session['formative_assessment'] ?? []);
            $formativeRows = [];
            $criteria = $this->stringList($formative['criteria'] ?? []);
            if ($criteria !== []) {
                $formativeRows[] = ['Criterios de evaluación', implode("\n", $criteria)];
            }
            $evidence = $this->stringList($formative['evidence'] ?? []);
            if ($evidence !== []) {
                $formativeRows[] = ['Evidencias del bloque', implode("\n", $evidence)];
            }
            if ($this->value($formative['feedback_strategy'] ?? null) !== '') {
                $formativeRows[] = ['Retroalimentación', $this->value($formative['feedback_strategy'] ?? null)];
            }
            if ($formativeRows !== []) {
                $blocks[] = ['type' => 'two_column_table', 'title' => 'Evaluación formativa', 'rows' => $formativeRows];
            }

            $diff = $this->object($session['differentiation'] ?? []);
            $supportRows = [];
            foreach ([
                'Apoyos' => 'support',
                'Desafíos' => 'challenge',
                'Accesibilidad' => 'accessibility',
            ] as $label => $key) {
                $items = $this->stringList($diff[$key] ?? []);
                if ($items !== []) {
                    $supportRows[] = [$label, implode("\n", $items)];
                }
            }
            if ($supportRows !== []) {
                $blocks[] = ['type' => 'two_column_table', 'title' => 'Atención a la diversidad', 'rows' => $supportRows];
            }
            if ($this->value($session['teacher_notes'] ?? null) !== '') {
                $blocks[] = ['type' => 'small_note', 'label' => 'Notas para el docente', 'text' => $this->value($session['teacher_notes'] ?? null)];
            }
            if ($this->value($session['homework_or_extension'] ?? null) !== '') {
                $blocks[] = ['type' => 'small_note', 'label' => 'Tarea o extensión', 'text' => $this->value($session['homework_or_extension'] ?? null)];
            }
        }

        $blocks[] = ['type' => 'page_break'];
        $blocks[] = ['type' => 'section', 'text' => 'Evaluación e instrumentos'];
        $assessmentRows = [];
        foreach ([
            'Diagnóstico' => $assessment['diagnostic'] ?? null,
            'Seguimiento' => $assessment['ongoing'] ?? null,
            'Cierre' => $assessment['closure'] ?? null,
        ] as $label => $value) {
            if ($this->value($value) !== '') {
                $assessmentRows[] = [$label, $this->value($value)];
            }
        }
        if ($assessmentRows !== []) {
            $blocks[] = ['type' => 'two_column_table', 'rows' => $assessmentRows];
        }

        foreach ($this->list($assessment['instruments'] ?? []) as $instrumentRaw) {
            $instrument = $this->object($instrumentRaw);
            $appliesTo = [];
            foreach ($this->stringList($instrument['applies_to_sessions'] ?? []) as $sessionId) {
                $appliesTo[] = $sessionFriendlyLabels[$sessionId] ?? $sessionId;
            }
            $blocks[] = [
                'type' => 'instrument',
                'name' => $this->value($instrument['name'] ?? null, 'Instrumento'),
                'purpose' => $this->value($instrument['purpose'] ?? null, ''),
                'criteria' => $this->stringList($instrument['criteria'] ?? []),
                'scale' => $this->stringList($instrument['scale'] ?? []),
                'sessions' => $appliesTo,
            ];
        }

        $physical = $this->stringList($resources['physical_materials'] ?? []);
        $digital = $this->stringList($resources['digital_resources'] ?? []);
        if ($physical !== [] || $digital !== []) {
            $blocks[] = ['type' => 'section', 'text' => 'Recursos generales'];
            if ($physical !== []) {
                $blocks[] = ['type' => 'list', 'label' => 'Materiales físicos', 'items' => $physical];
            }
            if ($digital !== []) {
                $blocks[] = ['type' => 'list', 'label' => 'Recursos digitales', 'items' => $digital];
            }
        }

        $adaptationRows = [];
        foreach ([
            'Basado en el perfil del grupo' => 'based_on_group_profile',
            'Supuestos utilizados' => 'assumptions',
        ] as $label => $key) {
            $items = $this->stringList($adaptation[$key] ?? []);
            if ($items !== []) {
                $adaptationRows[] = [$label, implode("\n", $items)];
            }
        }
        if ($adaptationRows !== []) {
            $blocks[] = ['type' => 'section', 'text' => 'Adecuaciones y notas'];
            $blocks[] = ['type' => 'two_column_table', 'rows' => $adaptationRows];
        }

        return $blocks;
    }

    /** @param mixed $value @return array<string,mixed> */
    private function object(mixed $value): array
    {
        return is_array($value) && ! array_is_list($value) ? $value : [];
    }

    /** @param mixed $value @return list<mixed> */
    private function list(mixed $value): array
    {
        return is_array($value) && array_is_list($value) ? array_values($value) : [];
    }

    /** @param mixed $value @return list<string> */
    private function stringList(mixed $value): array
    {
        return array_values(array_filter(array_map(
            static fn (mixed $item): string => trim(is_scalar($item) ? (string) $item : ''),
            $this->list($value),
        ), static fn (string $item): bool => $item !== ''));
    }

    private function value(mixed $value, string $fallback = ''): string
    {
        return is_scalar($value) && trim((string) $value) !== '' ? trim((string) $value) : $fallback;
    }

    private function namedList(mixed $value): string
    {
        $names = [];
        foreach ($this->list($value) as $rowRaw) {
            $row = $this->object($rowRaw);
            $text = trim((string) ($row['name'] ?? ''));
            if ($text !== '') {
                $names[] = $text;
            }
        }
        return implode('; ', array_values(array_unique($names)));
    }

    /** @param array<string,mixed> $planning */
    private function period(array $planning): string
    {
        $start = $this->value($planning['starts_on'] ?? null, '—');
        $end = $this->value($planning['ends_on'] ?? null, '—');
        return $start . ' → ' . $end;
    }

    /** @param array<string,mixed> $planning */
    private function sessionSummary(array $planning): string
    {
        $count = isset($planning['session_count']) ? (int) $planning['session_count'] : 0;
        $minutes = isset($planning['session_minutes']) ? (int) $planning['session_minutes'] : 0;
        if ($count < 1) {
            return '—';
        }
        return $count . ' bloque(s) de trabajo' . ($minutes > 0 ? ' · ' . $minutes . ' min aprox.' : '');
    }

    /**
     * Asocia cada bloque generado con el bloque real del horario por fecha y
     * posición. La generación ya se valida contra ese mismo orden.
     *
     * @param list<mixed> $sessions
     * @param array<string,mixed> $context
     * @return array<int,array<string,mixed>>
     */
    private function sessionScheduleSlots(array $sessions, array $context): array
    {
        $slotsByDate = [];
        foreach ($this->list($context['planning_calendar'] ?? []) as $dayRaw) {
            $day = $this->object($dayRaw);
            $date = trim((string) ($day['date'] ?? ''));
            if ($date === '') {
                continue;
            }
            foreach ($this->list($day['blocks'] ?? []) as $slotRaw) {
                $slot = $this->object($slotRaw);
                if (! (bool) ($slot['include_in_planning'] ?? false)) {
                    continue;
                }
                $slotsByDate[$date][] = $slot;
            }
        }

        $usedByDate = [];
        $result = [];
        foreach ($sessions as $index => $sessionRaw) {
            $session = $this->object($sessionRaw);
            $date = trim((string) ($session['date'] ?? ''));
            $position = $usedByDate[$date] ?? 0;
            $result[$index] = $date !== '' ? ($slotsByDate[$date][$position] ?? []) : [];
            if ($date !== '') {
                $usedByDate[$date] = $position + 1;
            }
        }

        return $result;
    }

    /** @param array<string,mixed> $slot @param array<string,mixed> $session */
    private function slotName(array $slot, array $session): string
    {
        foreach (['subject_name_snapshot', 'label'] as $key) {
            $value = trim((string) ($slot[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        return $this->value($session['title'] ?? null, 'Bloque de trabajo');
    }

    /** @param array<string,mixed> $slot */
    private function slotTime(array $slot): string
    {
        $start = trim((string) ($slot['starts_at'] ?? ''));
        $end = trim((string) ($slot['ends_at'] ?? ''));
        if ($start === '' && $end === '') {
            return '';
        }
        if ($start === '') {
            return 'Hasta ' . $end;
        }
        if ($end === '') {
            return 'Desde ' . $start;
        }

        return $start . '–' . $end;
    }

    /** @param array<string,mixed> $session @param array<string,mixed> $slot */
    private function friendlySessionLabel(array $session, array $slot): string
    {
        $date = $this->value($session['date'] ?? null, 'Fecha por definir');
        $name = $this->slotName($slot, $session);
        $time = $this->slotTime($slot);

        return trim($date . ' · ' . $name . ($time !== '' ? ' · ' . $time : ''));
    }

    private function organizationLabel(string $value): string
    {
        return match ($value) {
            'whole_group' => 'Grupo completo',
            'individual' => 'Individual',
            'pairs' => 'Parejas',
            'small_groups' => 'Equipos pequeños',
            'mixed' => 'Organización mixta',
            default => $value,
        };
    }
}
