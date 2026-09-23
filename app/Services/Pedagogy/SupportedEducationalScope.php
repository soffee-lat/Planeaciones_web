<?php

namespace App\Services\Pedagogy;

use App\Enums\EducationalLevel;
use App\Models\Curriculum;
use App\Models\Grade;
use RuntimeException;

final class SupportedEducationalScope
{
    /**
     * Verifica el alcance productivo de Planeaciones Soffee.
     *
     * Sólo se admiten:
     * - Preescolar: Fase 2, grados 1.º a 3.º.
     * - Primaria: Fases 3, 4 y 5, grados 1.º a 6.º.
     *
     * @return array{level:EducationalLevel,phase_code:string,grade_ordinal:int}
     */
    public function assert(Curriculum $curriculum, Grade $grade): array
    {
        $level = EducationalLevel::tryFrom(strtolower(trim((string) $curriculum->educational_level)));
        if (! $level) {
            throw new RuntimeException('EDUCATIONAL_LEVEL_NOT_SUPPORTED');
        }

        $grade->loadMissing('educationalPhase');
        $phaseCode = strtoupper(trim((string) ($grade->educationalPhase?->code ?? '')));
        $ordinal = (int) $grade->ordinal;

        $valid = match ($level) {
            EducationalLevel::Preschool =>
                $phaseCode === 'F2' && $ordinal >= 1 && $ordinal <= 3,
            EducationalLevel::Primary =>
                ($phaseCode === 'F3' && in_array($ordinal, [1, 2], true))
                || ($phaseCode === 'F4' && in_array($ordinal, [3, 4], true))
                || ($phaseCode === 'F5' && in_array($ordinal, [5, 6], true)),
        };

        if (! $valid) {
            throw new RuntimeException('EDUCATIONAL_PHASE_GRADE_NOT_SUPPORTED');
        }

        return [
            'level' => $level,
            'phase_code' => $phaseCode,
            'grade_ordinal' => $ordinal,
        ];
    }
}
