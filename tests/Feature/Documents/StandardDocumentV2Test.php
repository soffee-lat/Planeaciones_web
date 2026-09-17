<?php

namespace Tests\Feature\Documents;

use App\Services\Documents\CanonicalPlanDocumentBuilder;
use App\Services\Documents\StandardDocxRenderer;
use App\Services\Documents\StandardPdfRenderer;
use Tests\TestCase;

class StandardDocumentV2Test extends TestCase
{
    public function test_standard_document_is_structured_for_teacher_use_instead_of_plain_text_dump(): void
    {
        $plan = [
            'planning' => [
                'title' => 'La Independencia de México: aprender jugando',
                'project_name' => 'Independencia de México',
                'topic' => 'Comprender la importancia de la fecha mediante juegos.',
                'starts_on' => '2026-09-21',
                'ends_on' => '2026-09-25',
                'session_count' => 1,
                'session_minutes' => 50,
            ],
            'context' => [
                'school_name' => 'Escuela de prueba',
                'group_name' => '2.º A',
            ],
            'curricular_alignment' => [
                'grade' => ['name' => 'Segundo grado'],
                'phase' => ['name' => 'Fase 3'],
                'fields' => [['code' => 'FF-LANG', 'name' => 'Lenguajes']],
                'articulating_axes' => [['code' => 'AX-INCL', 'name' => 'Inclusión']],
                'contents' => [[
                    'code' => 'CT-01',
                    'title' => 'Contenido de prueba',
                    'full_text' => 'Contenido de prueba para validar el diseño.',
                ]],
                'pdas' => [[
                    'code' => 'PDA-01',
                    'full_text' => 'PDA de prueba para validar el diseño.',
                ]],
            ],
            'pedagogical_design' => [
                'purpose' => 'Comprender el tema mediante experiencias lúdicas y colaborativas.',
                'problem_or_interest' => 'Reconocer por qué esta fecha es importante.',
                'learning_goals' => ['Explicar una idea principal con sus propias palabras.'],
                'methodology' => [
                    'name' => 'Aprendizaje activo',
                    'rationale' => 'El grupo aprende mejor mediante actividades prácticas.',
                    'phases' => ['Explorar', 'Aplicar', 'Reflexionar'],
                ],
                'transversal_connections' => [[
                    'description' => 'Expresión oral y colaboración.',
                    'field_codes' => ['FF-LANG'],
                ]],
            ],
            'sessions' => [[
                'id' => 'S01',
                'sequence' => 1,
                'date' => '2026-09-21',
                'title' => '¿Qué sabemos?',
                'estimated_minutes' => 50,
                'specific_goal' => 'Recuperar ideas previas.',
                'field_codes' => ['FF-LANG'],
                'content_codes' => ['CT-01'],
                'pda_codes' => ['PDA-01'],
                'axis_codes' => ['AX-INCL'],
                'moments' => [
                    [
                        'type' => 'inicio',
                        'minutes' => 10,
                        'activities' => [[
                            'instruction' => 'Jugar La pelota pregunta.',
                            'teacher_action' => 'Explica la dinámica y recupera ideas.',
                            'student_action' => 'Participa y comparte una idea.',
                            'organization' => 'whole_group',
                            'materials' => ['Pelota suave', 'Pizarrón'],
                            'expected_evidence' => ['Participación oral'],
                            'assessment_checks' => ['Expresa una idea relacionada con el tema'],
                        ]],
                    ],
                    [
                        'type' => 'desarrollo',
                        'minutes' => 30,
                        'activities' => [[
                            'instruction' => 'Resolver un reto por equipos.',
                            'teacher_action' => 'Acompaña con preguntas.',
                            'student_action' => 'Colabora y explica sus respuestas.',
                            'organization' => 'small_groups',
                            'materials' => ['Tarjetas'],
                            'expected_evidence' => ['Respuesta del equipo'],
                            'assessment_checks' => ['Justifica una respuesta'],
                        ]],
                    ],
                    [
                        'type' => 'cierre',
                        'minutes' => 10,
                        'activities' => [[
                            'instruction' => 'Completar un semáforo de aprendizaje.',
                            'teacher_action' => 'Recupera conclusiones.',
                            'student_action' => 'Expresa qué aprendió.',
                            'organization' => 'individual',
                            'materials' => ['Hoja', 'Colores'],
                            'expected_evidence' => ['Semáforo individual'],
                            'assessment_checks' => ['Reconoce un aprendizaje'],
                        ]],
                    ],
                ],
                'formative_assessment' => [
                    'criteria' => ['Expresa una idea relacionada con el tema.', 'Participa respetando turnos.'],
                    'evidence' => ['Participación oral', 'Semáforo individual'],
                    'instrument_ids' => ['I01'],
                    'feedback_strategy' => 'Retroalimentación breve e inmediata.',
                ],
                'differentiation' => [
                    'support' => ['Dar consignas breves.'],
                    'challenge' => ['Pedir una explicación adicional.'],
                    'accessibility' => ['Permitir respuesta oral o gráfica.'],
                ],
                'homework_or_extension' => null,
            ]],
            'assessment_plan' => [
                'diagnostic' => 'Recuperar ideas previas.',
                'ongoing' => 'Observar participación y explicaciones.',
                'closure' => 'Revisar la evidencia final.',
                'instruments' => [[
                    'id' => 'I01',
                    'type' => 'checklist',
                    'name' => 'Lista de cotejo',
                    'purpose' => 'Registrar comprensión y participación.',
                    'criteria' => ['Expresa una idea relacionada con el tema.', 'Participa respetando turnos.'],
                    'applies_to_sessions' => ['S01'],
                    'scale' => ['Sí', 'En proceso', 'Requiere apoyo'],
                ]],
            ],
            'resources' => [
                'physical_materials' => ['Pelota suave', 'Tarjetas', 'Hojas'],
                'digital_resources' => [],
                'provided_references' => [],
            ],
            'adaptation_notes' => [
                'based_on_group_profile' => ['Se priorizan actividades prácticas.'],
                'assumptions' => ['Sesión de 50 minutos.'],
                'missing_information' => [],
            ],
        ];

        $blocks = (new CanonicalPlanDocumentBuilder())->build($plan);
        $types = array_column($blocks, 'type');

        $this->assertContains('meta_table', $types);
        $this->assertContains('table', $types);
        $this->assertContains('session_header', $types);
        $this->assertContains('activity', $types);
        $this->assertContains('checklist', $types);
        $this->assertContains('instrument', $types);

        $docx = (new StandardDocxRenderer())->render($blocks);

        $this->assertStringStartsWith("PK\x03\x04", $docx);
        $this->assertStringContainsString('<w:tbl', $docx);
        $this->assertStringContainsString('Vista semanal', $docx);
        $this->assertStringContainsString('DOCENTE', $docx);
        $this->assertStringContainsString('ALUMNOS', $docx);
        $this->assertStringContainsString('Evaluación formativa', $docx);
        $this->assertStringContainsString('Lista de cotejo', $docx);
        $this->assertStringContainsString('Sí', $docx);
        $this->assertStringContainsString('En proceso', $docx);
        $this->assertStringContainsString('<w:tblHeader/>', $docx);
        $this->assertStringContainsString('<w:cantSplit/>', $docx);
        $this->assertStringContainsString('<w:pageBreakBefore/>', $docx);

        $pdf = (new StandardPdfRenderer())->render($blocks);

        $this->assertStringStartsWith('%PDF-1.4', $pdf);
        $this->assertStringContainsString('Vista semanal', $pdf);
        $this->assertStringContainsString('DOCENTE', $pdf);
        $this->assertStringContainsString('ALUMNOS', $pdf);
        $this->assertStringContainsString('Actividad central:', $pdf);
        $this->assertStringContainsString('Lista de cotejo', $pdf);
        $this->assertStringNotContainsString('Fecha | Sesión | Objetivo | Actividad central | Evidencia', $pdf);
    }
}
