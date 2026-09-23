<?php

namespace App\Services\Documents;

final class InstitutionalFormatFieldCatalog
{
    /** @return array<string,string> */
    public function options(): array
    {
        return [
            'planning.title' => 'Título de la planeación',
            'planning.project_name' => 'Proyecto',
            'planning.topic' => 'Tema',
            'planning.starts_on' => 'Fecha de inicio',
            'planning.ends_on' => 'Fecha de término',
            'context.educational_level_label' => 'Nivel educativo',
            'curricular_alignment.grade.name' => 'Grado',
            'curricular_alignment.phase.name' => 'Fase',
            'curricular_alignment.fields' => 'Campo(s) formativo(s)',
            'curricular_alignment.contents' => 'Contenido(s)',
            'curricular_alignment.pdas' => 'PDA / Procesos de Desarrollo de Aprendizaje',
            'curricular_alignment.articulating_axes' => 'Ejes articuladores',
            'pedagogical_design.purpose' => 'Propósito',
            'pedagogical_design.problem_or_interest' => 'Problema o interés',
            'pedagogical_design.methodology' => 'Metodología',
            'sessions' => 'Secuencia didáctica / sesiones',
            'sessions.opening' => 'Actividades de inicio',
            'sessions.development' => 'Actividades de desarrollo',
            'sessions.closing' => 'Actividades de cierre',
            'assessment_plan' => 'Evaluación',
            'assessment_plan.diagnostic' => 'Evaluación diagnóstica',
            'assessment_plan.ongoing' => 'Seguimiento / evaluación continua',
            'assessment_plan.closure' => 'Evaluación de cierre',
            'resources' => 'Recursos / materiales',
            'resources.physical_materials' => 'Materiales físicos',
            'resources.digital_resources' => 'Recursos digitales',
            'adaptation_notes' => 'Adecuaciones / atención a la diversidad',
            'context' => 'Contexto / características del grupo',
            'context.group_name' => 'Grupo',
        ];
    }

    public function labelFor(string $path): string
    {
        return $this->options()[$path] ?? $path;
    }

    /** @return array{path:string,confidence:int}|null */
    public function suggest(string $label): ?array
    {
        $text = $this->normalize($label);
        if ($text === '') {
            return null;
        }

        // Use ~ as delimiter because several expressions contain alternatives
        // with anchored branches. This avoids accidental '/' termination.
        $rules = [
            ['~(?:titulo|nombre) de (?:la )?planeacion|^titulo$~', 'planning.title', 96],
            ['~^(?:titulo|nombre) del proyecto$|^(?:nombre del )?proyecto$|^proyecto(?: didactico)?$~', 'planning.project_name', 95],
            ['~^tema(?: central)?$|^tematica$~', 'planning.topic', 95],
            ['~^fecha$|fecha (?:de )?inicio|inicio del periodo~', 'planning.starts_on', 92],
            ['~fecha (?:de )?(?:termino|fin)|fin del periodo~', 'planning.ends_on', 92],
            ['~^nivel educativo$|^nivel$|preescolar|kinder|primaria~', 'context.educational_level_label', 96],
            ['~^grado(?: escolar)?$~', 'curricular_alignment.grade.name', 98],
            ['~^grupo$~', 'context.group_name', 98],
            ['~^fase(?: educativa)?$~', 'curricular_alignment.phase.name', 98],
            ['~campos? formativos?~', 'curricular_alignment.fields', 98],
            ['~^contenidos?(?: curriculares?)?$~', 'curricular_alignment.contents', 96],
            ['~\bpda\b|procesos? de desarrollo de (?:los )?aprendizajes?~', 'curricular_alignment.pdas', 99],
            ['~ejes? articuladores?~', 'curricular_alignment.articulating_axes', 99],
            ['~^proposito(?: de aprendizaje| didactico)?$|^intencion didactica$~', 'pedagogical_design.purpose', 96],
            ['~problema(?:tica)?(?: o interes)?|situacion problema~', 'pedagogical_design.problem_or_interest', 91],
            ['~^metodologia$|enfoque metodologico~', 'pedagogical_design.methodology', 95],
            ['~secuencia didactica|secuencia de sesiones|^sesiones$|^actividades$~', 'sessions', 90],
            ['~^(?:actividad(?:es)? de )?inicio$|^apertura$~', 'sessions.opening', 92],
            ['~^(?:actividad(?:es)? de )?desarrollo$~', 'sessions.development', 92],
            ['~^(?:actividad(?:es)? de )?cierre$~', 'sessions.closing', 90],
            ['~evaluacion diagnostica|diagnostico inicial~', 'assessment_plan.diagnostic', 96],
            ['~evaluacion continua|seguimiento|evaluacion formativa~', 'assessment_plan.ongoing', 92],
            ['~evaluacion final|evaluacion de cierre~', 'assessment_plan.closure', 94],
            ['~^evaluacion$|plan de evaluacion|instrumentos? de evaluacion~', 'assessment_plan', 90],
            ['~^fisicos?$|^materiales fisicos$~', 'resources.physical_materials', 94],
            ['~^digitales?$|^recursos digitales$~', 'resources.digital_resources', 94],
            ['~recursos|materiales|material didactico~', 'resources', 89],
            ['~adecuaciones|ajustes razonables|atencion a la diversidad|inclusion~', 'adaptation_notes', 92],
            ['~contexto del grupo|caracteristicas del grupo|diagnostico del grupo~', 'context', 88],
        ];

        foreach ($rules as [$pattern, $path, $confidence]) {
            if (preg_match($pattern, $text) === 1) {
                return ['path' => $path, 'confidence' => $confidence];
            }
        }

        return null;
    }

    public function suggestToken(string $token): ?string
    {
        $token = strtoupper(trim($token));

        return [
            'TITLE' => 'planning.title',
            'PROJECT' => 'planning.project_name',
            'PROJECT_NAME' => 'planning.project_name',
            'TOPIC' => 'planning.topic',
            'START_DATE' => 'planning.starts_on',
            'END_DATE' => 'planning.ends_on',
            'EDUCATIONAL_LEVEL' => 'context.educational_level_label',
            'LEVEL' => 'context.educational_level_label',
            'GRADE' => 'curricular_alignment.grade.name',
            'PHASE' => 'curricular_alignment.phase.name',
            'FIELD' => 'curricular_alignment.fields',
            'FIELDS' => 'curricular_alignment.fields',
            'CONTENT' => 'curricular_alignment.contents',
            'CONTENTS' => 'curricular_alignment.contents',
            'PDA' => 'curricular_alignment.pdas',
            'PDAS' => 'curricular_alignment.pdas',
            'AXES' => 'curricular_alignment.articulating_axes',
            'PURPOSE' => 'pedagogical_design.purpose',
            'METHODOLOGY' => 'pedagogical_design.methodology',
            'SESSIONS' => 'sessions',
            'OPENING' => 'sessions.opening',
            'DEVELOPMENT' => 'sessions.development',
            'CLOSING' => 'sessions.closing',
            'ASSESSMENT' => 'assessment_plan',
            'RESOURCES' => 'resources',
            'ADAPTATIONS' => 'adaptation_notes',
            'CONTEXT' => 'context',
        ][$token] ?? null;
    }

    private function normalize(string $value): string
    {
        $value = mb_strtolower(html_entity_decode($value, ENT_QUOTES | ENT_XML1, 'UTF-8'));
        $value = strtr($value, [
            'á' => 'a', 'à' => 'a', 'ä' => 'a', 'â' => 'a',
            'é' => 'e', 'è' => 'e', 'ë' => 'e', 'ê' => 'e',
            'í' => 'i', 'ì' => 'i', 'ï' => 'i', 'î' => 'i',
            'ó' => 'o', 'ò' => 'o', 'ö' => 'o', 'ô' => 'o',
            'ú' => 'u', 'ù' => 'u', 'ü' => 'u', 'û' => 'u',
            'ñ' => 'n',
        ]);
        $value = preg_replace('/[_]{3,}/u', ' ', $value) ?? $value;
        $value = preg_replace('/[^a-z0-9\s\/()-]+/u', ' ', $value) ?? $value;
        $value = preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);

        return trim($value, " \t\n\r\0\x0B-_/()");
    }
}
