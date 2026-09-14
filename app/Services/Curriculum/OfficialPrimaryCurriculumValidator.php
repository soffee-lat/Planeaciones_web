<?php

namespace App\Services\Curriculum;

use App\Models\CurriculumVersion;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Editorial gate for the first real production catalog:
 * México · NEM · Primaria · Fases 3, 4 y 5.
 *
 * This deliberately validates provenance and coverage before publication.
 * It does not try to infer official content: contents/PDA must arrive already
 * transcribed and source-located in the import JSON.
 */
final class OfficialPrimaryCurriculumValidator
{
    public const CURRICULUM_CODE = 'MX-NEM-PRIMARIA';

    /** @var list<string> */
    public const OFFICIAL_SOURCE_FILENAMES = [
        'Programa_Sintetico_Fase_3.pdf',
        'Programa_Sintetico_Fase_4.pdf',
        'Programa_Sintetico_Fase_5.pdf',
    ];

    /** @var list<string> */
    private const REQUIRED_FIELDS = [
        'Lenguajes',
        'Saberes y Pensamiento Científico',
        'Ética, Naturaleza y Sociedades',
        'De lo Humano y lo Comunitario',
    ];

    /** @var list<string> */
    private const REQUIRED_AXES = [
        'Inclusión',
        'Pensamiento crítico',
        'Interculturalidad crítica',
        'Igualdad de género',
        'Vida saludable',
        'Apropiación de las culturas a través de la lectura y la escritura',
        'Artes y experiencias estéticas',
    ];

    /** @var list<string> */
    private const BLOCKED_TEXT = [
        'demo',
        'ficticio',
        'ficticia',
        'sin validez curricular',
        '__pending_editorial__',
        'contenido de ejemplo',
        'pda de ejemplo',
    ];

    public function assertReadyForPublication(CurriculumVersion $version): void
    {
        $version->loadMissing([
            'curriculum',
            'phases',
            'grades.educationalPhase',
            'formativeFields',
            'curricularContents',
            'pdas.grade',
            'articulatingAxes',
        ]);

        if ($version->published_at !== null) {
            throw new RuntimeException('OFFICIAL_PRIMARY_VERSION_ALREADY_PUBLISHED');
        }

        $curriculum = $version->curriculum;
        if (! $curriculum
            || $curriculum->code !== self::CURRICULUM_CODE
            || mb_strtoupper((string) $curriculum->country_code) !== 'MX'
            || ! in_array($this->normalize((string) $curriculum->educational_level), ['primaria', 'primary'], true)) {
            throw new RuntimeException('OFFICIAL_PRIMARY_IDENTITY_INVALID');
        }

        $source = (string) $version->source_reference;
        if (! str_contains(mb_strtolower($source), 'educacionbasica.sep.gob.mx')) {
            throw new RuntimeException('OFFICIAL_PRIMARY_SOURCE_DOMAIN_REQUIRED');
        }
        foreach (self::OFFICIAL_SOURCE_FILENAMES as $filename) {
            if (! str_contains($source, $filename)) {
                throw new RuntimeException('OFFICIAL_PRIMARY_SOURCE_MISSING:' . $filename);
            }
        }

        $phaseNames = $version->phases->pluck('name')->map(fn ($v) => $this->normalize((string) $v))->all();
        foreach (['fase 3', 'fase 4', 'fase 5'] as $required) {
            if (! in_array($required, $phaseNames, true)) {
                throw new RuntimeException('OFFICIAL_PRIMARY_PHASE_MISSING:' . $required);
            }
        }
        if ($version->phases->count() !== 3) {
            throw new RuntimeException('OFFICIAL_PRIMARY_PHASE_COUNT_INVALID');
        }

        $grades = $version->grades->sortBy('ordinal')->values();
        if ($grades->count() !== 6 || $grades->pluck('ordinal')->map(fn ($v) => (int) $v)->all() !== [1, 2, 3, 4, 5, 6]) {
            throw new RuntimeException('OFFICIAL_PRIMARY_GRADES_INVALID');
        }
        foreach ($grades as $grade) {
            $expectedPhase = match ((int) $grade->ordinal) {
                1, 2 => 'fase 3',
                3, 4 => 'fase 4',
                5, 6 => 'fase 5',
                default => '',
            };
            if ($this->normalize((string) $grade->educationalPhase?->name) !== $expectedPhase) {
                throw new RuntimeException('OFFICIAL_PRIMARY_GRADE_PHASE_INVALID:' . $grade->code);
            }
        }

        $this->assertExactNames(
            $version->formativeFields->pluck('name')->all(),
            self::REQUIRED_FIELDS,
            'OFFICIAL_PRIMARY_FIELDS_INVALID'
        );
        $this->assertExactNames(
            $version->articulatingAxes->pluck('name')->all(),
            self::REQUIRED_AXES,
            'OFFICIAL_PRIMARY_AXES_INVALID'
        );

        if ($version->curricularContents->isEmpty() || $version->pdas->isEmpty()) {
            throw new RuntimeException('OFFICIAL_PRIMARY_CONTENT_EMPTY');
        }

        foreach ($version->curricularContents as $content) {
            if (blank($content->source_locator)) {
                throw new RuntimeException('OFFICIAL_PRIMARY_CONTENT_SOURCE_MISSING:' . $content->code);
            }
            $this->assertNoBlockedText([$content->title, $content->full_text], 'content:' . $content->code);
        }
        foreach ($version->pdas as $pda) {
            if (blank($pda->source_locator)) {
                throw new RuntimeException('OFFICIAL_PRIMARY_PDA_SOURCE_MISSING:' . $pda->code);
            }
            $this->assertNoBlockedText([$pda->full_text], 'pda:' . $pda->code);
        }

        // Completeness sanity: every phase/field pair represented in the
        // Programas Sintéticos must contain at least one content, and every
        // primary grade must have PDA. This catches partial/accidental imports
        // without inventing an undocumented exact row count.
        foreach ($version->phases as $phase) {
            foreach ($version->formativeFields as $field) {
                $exists = $version->curricularContents->contains(
                    fn ($content) => (int) $content->educational_phase_id === (int) $phase->id
                        && (int) $content->formative_field_id === (int) $field->id
                );
                if (! $exists) {
                    throw new RuntimeException('OFFICIAL_PRIMARY_PHASE_FIELD_EMPTY:' . $phase->code . ':' . $field->code);
                }
            }
        }
        foreach ($grades as $grade) {
            if (! $version->pdas->contains(fn ($pda) => (int) $pda->grade_id === (int) $grade->id)) {
                throw new RuntimeException('OFFICIAL_PRIMARY_GRADE_WITHOUT_PDA:' . $grade->code);
            }
        }
    }

    /** @param list<string> $actual @param list<string> $required */
    private function assertExactNames(array $actual, array $required, string $error): void
    {
        $a = collect($actual)->map(fn ($v) => $this->normalize((string) $v))->sort()->values()->all();
        $r = collect($required)->map(fn ($v) => $this->normalize((string) $v))->sort()->values()->all();
        if ($a !== $r) {
            throw new RuntimeException($error);
        }
    }

    /** @param list<mixed> $values */
    private function assertNoBlockedText(array $values, string $location): void
    {
        foreach ($values as $value) {
            $text = $this->normalize((string) $value);
            foreach (self::BLOCKED_TEXT as $marker) {
                if (str_contains($text, $this->normalize($marker))) {
                    throw new RuntimeException('OFFICIAL_PRIMARY_EDITORIAL_TEXT_INVALID:' . $location);
                }
            }
        }
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Str::squish($value));
    }
}
