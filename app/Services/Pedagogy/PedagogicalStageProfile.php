<?php

namespace App\Services\Pedagogy;

use App\Enums\EducationalLevel;
use App\Models\Curriculum;
use App\Models\Grade;

final class PedagogicalStageProfile
{
    public const SCHEMA_VERSION = 1;

    public function __construct(private SupportedEducationalScope $scope) {}

    /**
     * Perfil de calibración pedagógica para generación y auditoría.
     *
     * La banda es una heurística interna, no una escala oficial SEP ni un
     * diagnóstico del alumnado. El PDA, el contexto y el perfil real del grupo
     * siempre prevalecen.
     *
     * @return array<string,mixed>
     */
    public function for(Curriculum $curriculum, Grade $grade): array
    {
        $scope = $this->scope->assert($curriculum, $grade);
        $level = $scope['level'];
        $ordinal = $scope['grade_ordinal'];

        if ($level === EducationalLevel::Preschool) {
            return $this->preschool($grade, min(3, $ordinal));
        }

        if ($level === EducationalLevel::Primary) {
            return $this->primary($grade, min(6, $ordinal));
        }

        throw new \LogicException('EDUCATIONAL_LEVEL_NOT_SUPPORTED');
    }

    /** @return array<string,mixed> */
    private function preschool(Grade $grade, int $ordinal): array
    {
        return match ($ordinal) {
            1 => $this->profile(
                EducationalLevel::Preschool->value,
                EducationalLevel::Preschool->label(),
                $grade,
                'preschool_1',
                1,
                [
                    'Prioriza juego, exploración sensorial, movimiento, oralidad, canto, imágenes y manipulación de objetos.',
                    'Usa consignas breves, una acción principal por momento y alta mediación docente.',
                    'Parte de experiencias familiares, del entorno inmediato y de interacciones con pares.',
                    'Permite representar mediante gesto, dibujo, modelado, selección, clasificación, dramatización y lenguaje oral.',
                ],
                [
                    'No exijas lectura convencional, copia extensa, escritura autónoma de textos ni explicaciones abstractas como requisito general.',
                    'No prolongues actividades sedentarias o expositivas cuando el PDA puede trabajarse mediante acción y juego.',
                ],
                [
                    'Privilegia observación, conversación, registros anecdóticos, producciones gráficas y participación.',
                ],
            ),
            2 => $this->profile(
                EducationalLevel::Preschool->value,
                EducationalLevel::Preschool->label(),
                $grade,
                'preschool_2',
                2,
                [
                    'Combina juego y exploración con secuencias cortas de comparación, clasificación, narración y resolución práctica.',
                    'Promueve mayor intercambio entre pares y explicaciones orales sencillas sobre lo que observan o hacen.',
                    'Usa representaciones gráficas emergentes, marcas, colecciones, secuencias, dramatización y materiales concretos.',
                    'Mantén mediación docente visible, pero permite decisiones pequeñas y elección de estrategias.',
                ],
                [
                    'No conviertas representaciones emergentes en exigencia de escritura convencional si el PDA no lo requiere.',
                    'Evita tareas largas, repetitivas o centradas en fichas como recurso principal.',
                ],
                [
                    'Observa estrategias, comparaciones, lenguaje, interacción y cambios entre intentos.',
                ],
            ),
            default => $this->profile(
                EducationalLevel::Preschool->value,
                EducationalLevel::Preschool->label(),
                $grade,
                'preschool_3',
                3,
                [
                    'Mantén el juego, la exploración, el movimiento y los lenguajes artísticos como vías centrales de aprendizaje.',
                    'Propón secuencias un poco más sostenidas: anticipar, explorar, representar, explicar y compartir hallazgos.',
                    'Favorece mayor autonomía para organizar materiales, colaborar, tomar turnos y comunicar ideas.',
                    'Admite escritura y símbolos emergentes cuando resulten pertinentes, sin asumir dominio convencional de lectoescritura.',
                    'Prepara transiciones hacia primaria sin escolarizar prematuramente las experiencias de preescolar.',
                ],
                [
                    'No uses como regla general cuestionarios escritos, copias extensas, operaciones formales o exposiciones largas.',
                    'No fuerces razonamientos o productos que el PDA y la experiencia del grupo no sustenten.',
                ],
                [
                    'Combina observación, diálogo, producciones, desempeño en situaciones de juego y explicación de estrategias.',
                ],
            ),
        };
    }

    /** @return array<string,mixed> */
    private function primary(Grade $grade, int $ordinal): array
    {
        return match ($ordinal) {
            1 => $this->profile(
                EducationalLevel::Primary->value,
                EducationalLevel::Primary->label(),
                $grade,
                'primary_1',
                4,
                [
                    'Conserva fuerte apoyo concreto, oral, visual y manipulativo; introduce registros escolares breves y con andamiaje.',
                    'Trabaja lectura, escritura y pensamiento matemático como procesos en desarrollo, no como dominio supuesto.',
                    'Usa consignas cortas, modelado, ejemplos y pasos visibles; alterna actividad física, conversación y producción.',
                    'Pide explicaciones sencillas y evidencias breves acordes con el PDA.',
                ],
                [
                    'No presupongas lectura fluida, escritura extensa o autonomía sostenida.',
                    'Evita exceso de copia, definiciones descontextualizadas o trabajo abstracto sin apoyo concreto.',
                ],
                [
                    'Valora proceso, estrategias, oralidad, producciones breves y progresos en autonomía.',
                ],
            ),
            2 => $this->profile(
                EducationalLevel::Primary->value,
                EducationalLevel::Primary->label(),
                $grade,
                'primary_2',
                5,
                [
                    'Aumenta gradualmente la autonomía y la longitud de lecturas, producciones y problemas, manteniendo apoyos claros.',
                    'Propón tareas de dos o tres pasos, comparación de estrategias y explicaciones breves.',
                    'Combina materiales concretos con representaciones gráficas y registros escritos cada vez más convencionales.',
                ],
                [
                    'No asumas comprensión de textos largos ni producción escrita extensa sin andamiaje.',
                    'Evita convertir toda evidencia en examen o producto escrito.',
                ],
                [
                    'Integra observación, producciones breves, resolución de situaciones y explicación de procedimientos.',
                ],
            ),
            3 => $this->profile(
                EducationalLevel::Primary->value,
                EducationalLevel::Primary->label(),
                $grade,
                'primary_3',
                6,
                [
                    'Plantea secuencias de varios pasos con lectura, registro, comparación, clasificación, explicación y revisión.',
                    'Promueve trabajo colaborativo con roles sencillos y mayor independencia para consultar materiales.',
                    'Solicita producciones escritas y representaciones más organizadas cuando el PDA lo justifique.',
                ],
                [
                    'No conviertas la mayor autonomía en abandono del modelado o la retroalimentación.',
                    'Evita tareas de análisis propias de secundaria si no están sustentadas por el PDA.',
                ],
                [
                    'Valora claridad de explicaciones, uso de evidencias, estrategias y revisión del propio trabajo.',
                ],
            ),
            4 => $this->profile(
                EducationalLevel::Primary->value,
                EducationalLevel::Primary->label(),
                $grade,
                'primary_4',
                7,
                [
                    'Incrementa la investigación guiada, la comparación de fuentes, la organización de datos y la argumentación inicial.',
                    'Permite proyectos y problemas de varios pasos con decisiones del alumnado y responsabilidades de equipo.',
                    'Pide justificar procedimientos y revisar productos con criterios comprensibles.',
                ],
                [
                    'No confundas mayor complejidad con lenguaje innecesariamente técnico o cargas extensas.',
                    'No dependas sólo de productos finales para valorar el aprendizaje.',
                ],
                [
                    'Usa criterios explícitos, autoevaluación guiada, explicación de estrategias y evidencias variadas.',
                ],
            ),
            5 => $this->profile(
                EducationalLevel::Primary->value,
                EducationalLevel::Primary->label(),
                $grade,
                'primary_5',
                8,
                [
                    'Favorece análisis, relaciones causa-consecuencia, argumentación con evidencia, síntesis y resolución de problemas de varios pasos.',
                    'Integra lectura y producción de textos más estructurados, búsqueda guiada de información y comparación de perspectivas cuando el PDA lo requiera.',
                    'Promueve autonomía creciente para planear, distribuir tareas, revisar información y mejorar productos.',
                    'Mantén ejemplos, contextualización y oportunidades de interacción propias de niñas y niños de primaria.',
                ],
                [
                    'No conviertas el trabajo en tareas de nivel medio superior o universitario por usar vocabulario más complejo.',
                    'Evita investigación sin fuentes proporcionadas, criterios o acompañamiento.',
                ],
                [
                    'Valora argumentación, uso pertinente de evidencias, proceso de investigación, revisión y colaboración.',
                ],
            ),
            default => $this->profile(
                EducationalLevel::Primary->value,
                EducationalLevel::Primary->label(),
                $grade,
                'primary_6',
                9,
                [
                    'Integra análisis, síntesis, argumentación, resolución de problemas y proyectos con mayor autonomía, siempre dentro del nivel de primaria.',
                    'Permite productos estructurados, comparación crítica de información y explicación de decisiones cuando el PDA lo sustente.',
                    'Incluye revisión entre pares, metacognición sencilla y transferencia a situaciones del entorno.',
                    'Mantén lenguaje accesible, acompañamiento docente y experiencias significativas para niñas y niños.',
                ],
                [
                    'No adelantes contenidos o exigencias de secundaria sólo por tratarse del último grado de primaria.',
                    'No uses volumen de trabajo como sustituto de profundidad o comprensión.',
                ],
                [
                    'Valora integración de saberes, justificación, calidad de evidencias, revisión y capacidad de explicar lo aprendido.',
                ],
            ),
        };
    }

    /**
     * @param list<string> $guardrails
     * @param list<string> $avoid
     * @param list<string> $assessment
     * @return array<string,mixed>
     */
    private function profile(
        string $educationalLevel,
        string $educationalLevelLabel,
        Grade $grade,
        string $profileKey,
        int $complexityBand,
        array $guardrails,
        array $avoid,
        array $assessment,
    ): array {
        return [
            'schema_version' => self::SCHEMA_VERSION,
            'educational_level' => $educationalLevel,
            'educational_level_label' => $educationalLevelLabel,
            'grade_code' => (string) $grade->code,
            'grade_name' => (string) $grade->name,
            'grade_ordinal' => (int) $grade->ordinal,
            'profile_key' => $profileKey,
            'complexity_band' => $complexityBand,
            'complexity_note' => 'Heurística interna de calibración, no escala oficial ni límite rígido. El PDA, el contexto y el perfil real del grupo prevalecen.',
            'planning_guardrails' => [
                ...$guardrails,
                'Mantén actividades, lenguaje, productos y carga cognitiva apropiados para niñas y niños.',
                'Ajusta apoyos y desafíos con el perfil real del grupo; no homogeneices al alumnado por grado.',
            ],
            'avoid' => $avoid,
            'assessment_emphasis' => $assessment,
        ];
    }
}
