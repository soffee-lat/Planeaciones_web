<?php

namespace App\Services\Documents;

final class CanonicalPlanDocumentBuilder
{
    /**
     * @param array<string,mixed> $plan
     * @return list<array{type:string,text:string}>
     */
    public function build(array $plan): array
    {
        $blocks = [];
        $add = static function (string $type, ?string $text) use (&$blocks): void {
            $text = trim((string) $text);
            if ($text !== '') {
                $blocks[] = ['type' => $type, 'text' => $text];
            }
        };

        $planning = $this->object($plan['planning'] ?? []);
        $alignment = $this->object($plan['curricular_alignment'] ?? []);
        $pedagogy = $this->object($plan['pedagogical_design'] ?? []);
        $assessment = $this->object($plan['assessment_plan'] ?? []);
        $resources = $this->object($plan['resources'] ?? []);
        $adaptation = $this->object($plan['adaptation_notes'] ?? []);

        $add('title', $planning['title'] ?? 'Planeación didáctica');
        $add('paragraph', $this->labeled('Proyecto', $planning['project_name'] ?? null));
        $add('paragraph', $this->labeled('Tema', $planning['topic'] ?? null));
        $add('paragraph', sprintf(
            'Periodo: %s a %s | Sesiones: %s | Duración por sesión: %s min',
            (string) ($planning['starts_on'] ?? ''),
            (string) ($planning['ends_on'] ?? ''),
            (string) ($planning['session_count'] ?? ''),
            (string) ($planning['session_minutes'] ?? ''),
        ));

        $add('heading1', 'Datos curriculares');
        $grade = $this->object($alignment['grade'] ?? []);
        $phase = $this->object($alignment['phase'] ?? []);
        $curriculum = $this->object($alignment['curriculum'] ?? []);
        $add('paragraph', $this->labeled('Grado', $grade['name'] ?? $grade['code'] ?? null));
        $add('paragraph', $this->labeled('Fase', $phase['name'] ?? $phase['code'] ?? null));
        $add('paragraph', $this->labeled('Currículo', trim((string) ($curriculum['name'] ?? '') . ' ' . (string) ($curriculum['version'] ?? ''))));
        $add('paragraph', $this->labeled('Campos formativos', $this->namedList($alignment['fields'] ?? [])));
        $add('paragraph', $this->labeled('Ejes articuladores', $this->namedList($alignment['articulating_axes'] ?? [])));

        $add('heading2', 'Contenidos');
        foreach ($this->list($alignment['contents'] ?? []) as $content) {
            $row = $this->object($content);
            $code = trim((string) ($row['code'] ?? ''));
            $text = trim((string) ($row['full_text'] ?? $row['title'] ?? ''));
            $add('bullet', trim(($code !== '' ? $code . ' - ' : '') . $text));
        }

        $add('heading2', 'Procesos de Desarrollo de Aprendizaje (PDA)');
        foreach ($this->list($alignment['pdas'] ?? []) as $pda) {
            $row = $this->object($pda);
            $code = trim((string) ($row['code'] ?? ''));
            $text = trim((string) ($row['full_text'] ?? ''));
            $add('bullet', trim(($code !== '' ? $code . ' - ' : '') . $text));
        }

        $add('heading1', 'Contexto del grupo');
        foreach ($this->flattenObject($this->object($plan['context'] ?? [])) as [$label, $value]) {
            $add('paragraph', $this->labeled($this->humanize($label), $value));
        }

        $add('heading1', 'Diseño pedagógico');
        $add('heading2', 'Propósito');
        $add('paragraph', (string) ($pedagogy['purpose'] ?? ''));
        $add('paragraph', $this->labeled('Problema o interés', $pedagogy['problem_or_interest'] ?? null));
        $add('paragraph', $this->labeled('Escenario', $pedagogy['scenario'] ?? null));
        $add('heading2', 'Metas de aprendizaje');
        foreach ($this->stringList($pedagogy['learning_goals'] ?? []) as $item) {
            $add('bullet', $item);
        }
        $methodology = $this->object($pedagogy['methodology'] ?? []);
        $add('heading2', 'Metodología');
        $add('paragraph', $this->labeled('Enfoque', $methodology['name'] ?? null));
        $add('paragraph', (string) ($methodology['rationale'] ?? ''));
        foreach ($this->stringList($methodology['phases'] ?? []) as $phaseName) {
            $add('bullet', $phaseName);
        }
        $add('heading2', 'Conexiones transversales');
        foreach ($this->stringList($pedagogy['transversal_connections'] ?? []) as $item) {
            $add('bullet', $item);
        }

        $add('heading1', 'Secuencia de sesiones');
        foreach ($this->list($plan['sessions'] ?? []) as $sessionRaw) {
            $session = $this->object($sessionRaw);
            $sequence = (string) ($session['sequence'] ?? '');
            $sessionTitle = trim((string) ($session['title'] ?? 'Sesión'));
            $add('heading2', trim('Sesión ' . $sequence . ': ' . $sessionTitle));
            $add('paragraph', $this->labeled('Fecha', $session['date'] ?? 'Por definir'));
            $add('paragraph', $this->labeled('Meta específica', $session['specific_goal'] ?? null));
            $add('paragraph', $this->labeled('Fase metodológica', $session['methodology_phase'] ?? null));
            $add('paragraph', $this->labeled('Duración estimada', isset($session['estimated_minutes']) ? $session['estimated_minutes'] . ' min' : null));
            $add('paragraph', $this->labeled('Referencias curriculares', $this->codesForSession($session)));

            foreach ($this->list($session['moments'] ?? []) as $momentRaw) {
                $moment = $this->object($momentRaw);
                $momentName = ucfirst((string) ($moment['type'] ?? 'Momento'));
                $minutes = isset($moment['minutes']) ? ' (' . $moment['minutes'] . ' min)' : '';
                $add('heading3', $momentName . $minutes);
                foreach ($this->list($moment['activities'] ?? []) as $activityRaw) {
                    $activity = $this->object($activityRaw);
                    $add('bullet', (string) ($activity['instruction'] ?? ''));
                    $add('paragraph', $this->labeled('Acción docente', $activity['teacher_action'] ?? null));
                    $add('paragraph', $this->labeled('Acción del alumnado', $activity['student_action'] ?? null));
                    $add('paragraph', $this->labeled('Organización', $activity['organization'] ?? null));
                    $add('paragraph', $this->labeled('Materiales', $this->join($activity['materials'] ?? [])));
                    $add('paragraph', $this->labeled('Evidencia esperada', $this->join($activity['expected_evidence'] ?? [])));
                    $add('paragraph', $this->labeled('Verificación formativa', $this->join($activity['assessment_checks'] ?? [])));
                }
            }

            $formative = $this->object($session['formative_assessment'] ?? []);
            $add('heading3', 'Evaluación formativa de la sesión');
            $add('paragraph', $this->labeled('Criterios', $this->join($formative['criteria'] ?? [])));
            $add('paragraph', $this->labeled('Evidencias', $this->join($formative['evidence'] ?? [])));
            $add('paragraph', $this->labeled('Retroalimentación', $formative['feedback_strategy'] ?? null));

            $diff = $this->object($session['differentiation'] ?? []);
            $add('heading3', 'Atención a la diversidad');
            $add('paragraph', $this->labeled('Apoyos', $this->join($diff['support'] ?? [])));
            $add('paragraph', $this->labeled('Desafíos', $this->join($diff['challenge'] ?? [])));
            $add('paragraph', $this->labeled('Accesibilidad', $this->join($diff['accessibility'] ?? [])));
            $add('paragraph', $this->labeled('Tarea o extensión', $session['homework_or_extension'] ?? null));
            $add('paragraph', $this->labeled('Notas docentes', $session['teacher_notes'] ?? null));
        }

        $add('heading1', 'Plan de evaluación');
        $add('paragraph', $this->labeled('Diagnóstico', $assessment['diagnostic'] ?? null));
        $add('paragraph', $this->labeled('Seguimiento', $assessment['ongoing'] ?? null));
        $add('paragraph', $this->labeled('Cierre', $assessment['closure'] ?? null));
        foreach ($this->list($assessment['instruments'] ?? []) as $instrumentRaw) {
            $instrument = $this->object($instrumentRaw);
            $add('heading2', trim((string) ($instrument['name'] ?? 'Instrumento')));
            $add('paragraph', $this->labeled('Propósito', $instrument['purpose'] ?? null));
            $add('paragraph', $this->labeled('Criterios', $this->join($instrument['criteria'] ?? [])));
            $add('paragraph', $this->labeled('Aplica a', $this->join($instrument['applies_to_sessions'] ?? [])));
            $add('paragraph', $this->labeled('Escala', $this->join($instrument['scale'] ?? [])));
        }

        $add('heading1', 'Recursos');
        $add('paragraph', $this->labeled('Materiales físicos', $this->join($resources['physical_materials'] ?? [])));
        $add('paragraph', $this->labeled('Recursos digitales', $this->join($resources['digital_resources'] ?? [])));
        $add('paragraph', $this->labeled('Referencias proporcionadas', $this->join($resources['provided_references'] ?? [])));

        $add('heading1', 'Adecuaciones y notas');
        $add('paragraph', $this->labeled('Basado en perfil del grupo', $this->join($adaptation['based_on_group_profile'] ?? [])));
        $add('paragraph', $this->labeled('Supuestos', $this->join($adaptation['assumptions'] ?? [])));
        $add('paragraph', $this->labeled('Información faltante', $this->join($adaptation['missing_information'] ?? [])));

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

    private function join(mixed $value): string
    {
        if (is_scalar($value)) {
            return trim((string) $value);
        }
        return implode('; ', $this->stringList($value));
    }

    private function labeled(string $label, mixed $value): string
    {
        $text = is_scalar($value) ? trim((string) $value) : $this->join($value);
        return $text === '' ? '' : $label . ': ' . $text;
    }

    /** @param mixed $value */
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

    /** @param array<string,mixed> $session */
    private function codesForSession(array $session): string
    {
        $groups = [];
        foreach ([
            'Campos' => 'field_codes',
            'Contenidos' => 'content_codes',
            'PDA' => 'pda_codes',
            'Ejes' => 'axis_codes',
        ] as $label => $key) {
            $value = $this->join($session[$key] ?? []);
            if ($value !== '') {
                $groups[] = $label . ': ' . $value;
            }
        }
        return implode(' | ', $groups);
    }

    /**
     * @param array<string,mixed> $object
     * @return list<array{0:string,1:string}>
     */
    private function flattenObject(array $object, string $prefix = ''): array
    {
        $rows = [];
        foreach ($object as $key => $value) {
            $path = $prefix === '' ? (string) $key : $prefix . ' / ' . (string) $key;
            if (is_array($value) && ! array_is_list($value)) {
                $rows = [...$rows, ...$this->flattenObject($value, $path)];
                continue;
            }
            $text = $this->join($value);
            if ($text !== '') {
                $rows[] = [$path, $text];
            }
        }
        return $rows;
    }

    private function humanize(string $value): string
    {
        return ucfirst(str_replace('_', ' ', $value));
    }
}
