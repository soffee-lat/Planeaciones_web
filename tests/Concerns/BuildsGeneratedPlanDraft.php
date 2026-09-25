<?php

namespace Tests\Concerns;

use App\Models\PlanningRequest;

trait BuildsGeneratedPlanDraft
{
    /** @return array<string,mixed> */
    protected function generatedDraftFor(PlanningRequest $request, int $sessionCount = 2): array
    {
        $snapshot = $request->currentInputVersion?->snapshot ?? $request->input_snapshot;
        $curriculum = $snapshot['curriculum'];
        $fieldCode = $curriculum['formative_fields'][0]['code'];
        $contentCode = $curriculum['contents'][0]['code'];
        $pdaCode = $curriculum['pdas'][0]['code'];
        $axisCodes = array_values(array_map(fn (array $axis) => $axis['code'], $curriculum['axes'] ?? []));
        $profileMinutes = (int) ($snapshot['group']['profile']['session_minutes'] ?? 50);
        $calendar = is_array($snapshot['group']['planning_calendar'] ?? null)
            ? $snapshot['group']['planning_calendar']
            : [];

        $scheduleSlots = [];
        foreach ($calendar as $day) {
            $date = (string) ($day['date'] ?? '');
            foreach ((array) ($day['blocks'] ?? []) as $block) {
                if (! (bool) ($block['include_in_planning'] ?? false)) {
                    continue;
                }

                $scheduleSlots[] = [
                    'date' => $date !== '' ? $date : null,
                    'minutes' => max(1, (int) ($block['minutes'] ?? $profileMinutes)),
                ];
            }
        }

        if ($scheduleSlots === []) {
            $scheduleSlots = array_fill(0, $sessionCount, [
                'date' => null,
                'minutes' => $profileMinutes,
            ]);
        }

        $sessions = [];
        foreach ($scheduleSlots as $index => $slot) {
            $i = $index + 1;
            $id = 'S' . str_pad((string) $i, 2, '0', STR_PAD_LEFT);
            $minutes = (int) $slot['minutes'];
            $inicio = max(1, min(10, $minutes - 2));
            $cierre = max(1, min(10, $minutes - $inicio - 1));
            $desarrollo = $minutes - $inicio - $cierre;

            $sessions[] = [
                'id' => $id,
                'sequence' => $i,
                'date' => $slot['date'],
                'title' => "Sesión {$i}",
                'estimated_minutes' => $minutes,
                'methodology_phase' => null,
                'specific_goal' => 'Desarrollar una meta pedagógica ficticia para pruebas.',
                'field_codes' => [$fieldCode],
                'content_codes' => [$contentCode],
                'pda_codes' => [$pdaCode],
                'axis_codes' => $axisCodes,
                'moments' => [
                    [
                        'type' => 'inicio',
                        'minutes' => $inicio,
                        'activities' => [$this->activity('Recuperar saberes previos.')],
                    ],
                    [
                        'type' => 'desarrollo',
                        'minutes' => $desarrollo,
                        'activities' => [$this->activity('Resolver una actividad guiada.')],
                    ],
                    [
                        'type' => 'cierre',
                        'minutes' => $cierre,
                        'activities' => [$this->activity('Compartir conclusiones.')],
                    ],
                ],
                'formative_assessment' => [
                    'criteria' => ['Comunica una idea relacionada con la meta.'],
                    'evidence' => ['Producción ficticia'],
                    'instrument_ids' => ['I01'],
                    'feedback_strategy' => 'Retroalimentación descriptiva breve.',
                ],
                'differentiation' => [
                    'support' => ['Dar instrucciones por pasos cuando sea necesario.'],
                    'challenge' => [],
                    'accessibility' => [],
                ],
                'homework_or_extension' => null,
                'teacher_notes' => null,
            ];
        }

        return [
            'contract_version' => 'generated_plan_draft_v1',
            'title' => 'Planeación DEMO',
            'project_name' => $snapshot['request']['project'] ?? null,
            'purpose' => 'Propósito pedagógico ficticio para pruebas automatizadas.',
            'problem_or_interest' => null,
            'scenario' => 'Aula',
            'learning_goals' => ['Meta de aprendizaje ficticia.'],
            'methodology' => [
                'name' => 'Secuencia didáctica',
                'rationale' => 'Organización ficticia para pruebas.',
                'phases' => [],
            ],
            'transversal_connections' => [],
            'sessions' => $sessions,
            'assessment_plan' => [
                'approach' => 'formative',
                'diagnostic' => 'Recuperación breve de saberes previos.',
                'ongoing' => 'Observación y retroalimentación durante las actividades.',
                'closure' => 'Revisión de la evidencia de cierre.',
                'instruments' => [[
                    'id' => 'I01',
                    'type' => 'checklist',
                    'name' => 'Lista de cotejo DEMO',
                    'purpose' => 'Registrar evidencias ficticias.',
                    'criteria' => ['Comunica una idea relacionada con la meta.'],
                    'applies_to_sessions' => array_column($sessions, 'id'),
                    'scale' => ['Sí', 'En proceso'],
                ]],
            ],
            'resources' => [
                'physical_materials' => ['Hojas'],
                'digital_resources' => [],
                'provided_references' => [],
            ],
            'adaptation_notes' => [
                'based_on_group_profile' => ['Se considera el perfil pedagógico congelado.'],
                'assumptions' => [],
                'missing_information' => [],
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function activity(string $instruction): array
    {
        return [
            'instruction' => $instruction,
            'teacher_action' => 'Acompaña y formula preguntas.',
            'student_action' => 'Participa y produce una evidencia.',
            'organization' => 'whole_group',
            'materials' => ['Hojas'],
            'expected_evidence' => ['Evidencia ficticia'],
            'assessment_checks' => ['Identifica una idea relevante'],
        ];
    }
}
