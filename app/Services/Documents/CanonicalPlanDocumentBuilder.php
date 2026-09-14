<?php

namespace App\Services\Documents;

final class CanonicalPlanDocumentBuilder
{
    /**
     * Construye un view-model documental orientado a consulta docente.
     * No intenta serializar el canonical como texto: organiza la información
     * en resumen, tablas, sesiones, momentos, evaluación e instrumentos.
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
                'Sesiones', $this->sessionSummary($planning),
            ],
            [
                'Grado', $this->value($grade['name'] ?? $grade['code'] ?? null, '—'),
                'Fase', $this->value($phase['name'] ?? $phase['code'] ?? null, '—'),
            ],
        ];
        $blocks[] = ['type' => 'meta_table', 'rows' => $metaRows];

        $blocks[] = ['type' => 'section', 'text' => 'Propósito y enfoque'];
        $blocks[] = [
            'type' => 'callout',
            'label' => 'Propósito',
            'text' => $this->value($pedagogy['purpose'] ?? null, 'No especificado.'),
        ];
        if ($this->value($pedagogy['problem_or_interest'] ?? null) !== '') {
            $blocks[] = ['type' => 'key_value', 'label' => 'Problema o interés', 'text' => $this->value($pedagogy['problem_or_interest'] ?? null)];
        }
        $methodology = $this->object($pedagogy['methodology'] ?? []);
        if ($this->value($methodology['name'] ?? null) !== '') {
            $blocks[] = ['type' => 'key_value', 'label' => 'Metodología', 'text' => $this->value($methodology['name'] ?? null)];
        }
        if ($this->value($methodology['rationale'] ?? null) !== '') {
            $blocks[] = ['type' => 'paragraph', 'text' => $this->value($methodology['rationale'] ?? null)];
        }
        $goals = $this->stringList($pedagogy['learning_goals'] ?? []);
        if ($goals !== []) {
            $blocks[] = ['type' => 'list', 'label' => 'Metas de aprendizaje', 'items' => $goals];
        }

        $blocks[] = ['type' => 'section', 'text' => 'Alineación curricular'];
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
            $code = trim((string) ($content['code'] ?? ''));
            $text = trim((string) ($content['full_text'] ?? $content['title'] ?? ''));
            if ($text !== '') {
                $contents[] = trim(($code !== '' ? $code . ' · ' : '') . $text);
            }
        }
        if ($contents !== []) {
            $curriculumRows[] = ['Contenidos', implode("\n", $contents)];
        }
        $pdas = [];
        foreach ($this->list($alignment['pdas'] ?? []) as $pdaRaw) {
            $pda = $this->object($pdaRaw);
            $code = trim((string) ($pda['code'] ?? ''));
            $text = trim((string) ($pda['full_text'] ?? ''));
            if ($text !== '') {
                $pdas[] = trim(($code !== '' ? $code . ' · ' : '') . $text);
            }
        }
        if ($pdas !== []) {
            $curriculumRows[] = ['PDA', implode("\n", $pdas)];
        }
        $blocks[] = ['type' => 'two_column_table', 'rows' => $curriculumRows];

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

        if ($sessions !== []) {
            $blocks[] = ['type' => 'section', 'text' => 'Vista semanal'];
            $overviewRows = [];
            foreach ($sessions as $sessionRaw) {
                $session = $this->object($sessionRaw);
                $centralActivity = '';
                $evidence = '';
                foreach ($this->list($session['moments'] ?? []) as $momentRaw) {
                    $moment = $this->object($momentRaw);
                    if (($moment['type'] ?? null) !== 'desarrollo') {
                        continue;
                    }
                    $activity = $this->object($this->list($moment['activities'] ?? [])[0] ?? []);
                    $centralActivity = trim((string) ($activity['instruction'] ?? ''));
                    $evidence = implode('; ', $this->stringList($activity['expected_evidence'] ?? []));
                    break;
                }
                $overviewRows[] = [
                    $this->value($session['date'] ?? null, 'Por definir'),
                    $this->value($session['title'] ?? null, 'Sesión'),
                    $this->value($session['specific_goal'] ?? null, '—'),
                    $centralActivity !== '' ? $centralActivity : '—',
                    $evidence !== '' ? $evidence : '—',
                ];
            }
            $blocks[] = [
                'type' => 'table',
                'headers' => ['Fecha', 'Sesión', 'Objetivo', 'Actividad central', 'Evidencia'],
                'rows' => $overviewRows,
            ];
        }

        foreach ($sessions as $index => $sessionRaw) {
            $session = $this->object($sessionRaw);
            if ($index > 0) {
                $blocks[] = ['type' => 'page_break'];
            }
            $sequence = (string) ($session['sequence'] ?? ($index + 1));
            $sessionTitle = $this->value($session['title'] ?? null, 'Sesión');
            $date = $this->value($session['date'] ?? null, 'Fecha por definir');
            $minutes = isset($session['estimated_minutes']) ? (int) $session['estimated_minutes'] : null;
            $blocks[] = [
                'type' => 'session_header',
                'text' => 'Sesión ' . $sequence . ' · ' . $sessionTitle,
                'meta' => $date . ($minutes ? ' · ' . $minutes . ' min' : ''),
            ];
            $blocks[] = ['type' => 'callout', 'label' => 'Objetivo del día', 'text' => $this->value($session['specific_goal'] ?? null, 'No especificado.')];
            $codes = $this->codesForSession($session);
            if ($codes !== '') {
                $blocks[] = ['type' => 'small_note', 'label' => 'Referencias curriculares', 'text' => $codes];
            }

            foreach ($this->list($session['moments'] ?? []) as $momentRaw) {
                $moment = $this->object($momentRaw);
                $momentType = ucfirst((string) ($moment['type'] ?? 'Momento'));
                $momentMinutes = isset($moment['minutes']) ? (int) $moment['minutes'] : null;
                $blocks[] = [
                    'type' => 'moment_header',
                    'text' => $momentType,
                    'meta' => $momentMinutes ? $momentMinutes . ' min' : '',
                ];
                foreach ($this->list($moment['activities'] ?? []) as $activityRaw) {
                    $activity = $this->object($activityRaw);
                    $blocks[] = [
                        'type' => 'activity',
                        'instruction' => $this->value($activity['instruction'] ?? null, 'Actividad'),
                        'teacher_action' => $this->value($activity['teacher_action'] ?? null, '—'),
                        'student_action' => $this->value($activity['student_action'] ?? null, '—'),
                        'organization' => $this->organizationLabel((string) ($activity['organization'] ?? '')),
                        'materials' => $this->stringList($activity['materials'] ?? []),
                        'evidence' => $this->stringList($activity['expected_evidence'] ?? []),
                        'checks' => $this->stringList($activity['assessment_checks'] ?? []),
                    ];
                }
            }

            $formative = $this->object($session['formative_assessment'] ?? []);
            $criteria = $this->stringList($formative['criteria'] ?? []);
            if ($criteria !== []) {
                $blocks[] = ['type' => 'checklist', 'label' => 'Evaluación formativa', 'items' => $criteria];
            }
            $evidence = $this->stringList($formative['evidence'] ?? []);
            if ($evidence !== []) {
                $blocks[] = ['type' => 'list', 'label' => 'Evidencias', 'items' => $evidence];
            }
            if ($this->value($formative['feedback_strategy'] ?? null) !== '') {
                $blocks[] = ['type' => 'small_note', 'label' => 'Retroalimentación', 'text' => $this->value($formative['feedback_strategy'] ?? null)];
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
            if ($this->value($session['homework_or_extension'] ?? null) !== '') {
                $blocks[] = ['type' => 'small_note', 'label' => 'Tarea o extensión', 'text' => $this->value($session['homework_or_extension'] ?? null)];
            }
        }

        $blocks[] = ['type' => 'page_break'];
        $blocks[] = ['type' => 'section', 'text' => 'Evaluación e instrumentos'];
        foreach ([
            'Diagnóstico' => $assessment['diagnostic'] ?? null,
            'Seguimiento' => $assessment['ongoing'] ?? null,
            'Cierre' => $assessment['closure'] ?? null,
        ] as $label => $value) {
            if ($this->value($value) !== '') {
                $blocks[] = ['type' => 'key_value', 'label' => $label, 'text' => $this->value($value)];
            }
        }
        foreach ($this->list($assessment['instruments'] ?? []) as $instrumentRaw) {
            $instrument = $this->object($instrumentRaw);
            $blocks[] = [
                'type' => 'instrument',
                'name' => $this->value($instrument['name'] ?? null, 'Instrumento'),
                'purpose' => $this->value($instrument['purpose'] ?? null, ''),
                'criteria' => $this->stringList($instrument['criteria'] ?? []),
                'scale' => $this->stringList($instrument['scale'] ?? []),
                'sessions' => $this->stringList($instrument['applies_to_sessions'] ?? []),
            ];
        }

        $physical = $this->stringList($resources['physical_materials'] ?? []);
        $digital = $this->stringList($resources['digital_resources'] ?? []);
        if ($physical !== [] || $digital !== []) {
            $blocks[] = ['type' => 'section', 'text' => 'Recursos'];
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
            'Información faltante' => 'missing_information',
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
            $text = trim((string) ($row['name'] ?? $row['code'] ?? ''));
            if ($text !== '') {
                $names[] = $text;
            }
        }
        return implode('; ', $names);
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
        return $count . ' sesión(es)' . ($minutes > 0 ? ' · ' . $minutes . ' min' : '');
    }

    /** @param array<string,mixed> $session */
    private function codesForSession(array $session): string
    {
        $parts = [];
        foreach ([
            'Campos' => 'field_codes',
            'Contenidos' => 'content_codes',
            'PDA' => 'pda_codes',
            'Ejes' => 'axis_codes',
        ] as $label => $key) {
            $values = $this->stringList($session[$key] ?? []);
            if ($values !== []) {
                $parts[] = $label . ': ' . implode(', ', $values);
            }
        }
        return implode(' | ', $parts);
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
