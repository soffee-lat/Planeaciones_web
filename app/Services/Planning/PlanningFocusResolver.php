<?php

namespace App\Services\Planning;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;

final class PlanningFocusResolver
{
    /**
     * Enriquece el calendario congelado con el foco pedagógico semanal.
     *
     * @param list<array<string,mixed>> $calendar
     * @param Collection<int,\App\Models\PlanningRequestWeek> $weeks
     * @return list<array<string,mixed>>
     */
    public function enrich(array $calendar, Collection $weeks): array
    {
        return array_map(function (array $day) use ($weeks): array {
            $date = (string) ($day['date'] ?? '');
            $week = $weeks->first(fn ($candidate) =>
                $date !== ''
                && $candidate->starts_on?->toDateString() <= $date
                && $candidate->ends_on?->toDateString() >= $date
            );

            if (! $week) {
                return $day;
            }

            $topics = $week->topics->map(fn ($topic) => [
                'topic' => (string) $topic->topic,
                'notes' => $topic->notes,
                'group_subject_id' => $topic->group_subject_id ? (int) $topic->group_subject_id : null,
                'subject_name' => $topic->subject?->name,
                'subject_color' => $topic->subject?->color,
                'subject_field_code' => $topic->subject?->curriculum_field_code,
            ])->values();

            $blocks = array_map(function (array $block) use ($week, $topics): array {
                $subjectId = isset($block['group_subject_id']) && $block['group_subject_id'] !== null
                    ? (int) $block['group_subject_id']
                    : null;

                $primary = $topics
                    ->filter(fn (array $topic): bool => $this->topicMatchesBlock($topic, $block, $subjectId))
                    ->values();

                $transversalCandidates = $topics
                    ->reject(fn (array $topic): bool => $this->topicMatchesBlock($topic, $block, $subjectId))
                    ->values();

                $isPlanable = (bool) ($block['include_in_planning'] ?? false);
                $isTransversal = $isPlanable && $primary->isEmpty() && $transversalCandidates->isNotEmpty();

                return [
                    ...$block,
                    'planning_week_sequence' => (int) $week->sequence,
                    'planning_week_label' => (string) $week->label,
                    'primary_topics' => $primary->all(),
                    'transversal' => $isTransversal,
                    'available_transversal_topics' => $isTransversal ? $transversalCandidates->all() : [],
                ];
            }, is_array($day['blocks'] ?? null) ? $day['blocks'] : []);

            return [
                ...$day,
                'planning_week_sequence' => (int) $week->sequence,
                'planning_week_label' => (string) $week->label,
                'blocks' => $blocks,
            ];
        }, $calendar);
    }

    /** @param array<string,mixed> $topic @param array<string,mixed> $block */
    private function topicMatchesBlock(array $topic, array $block, ?int $subjectId): bool
    {
        $topicSubjectId = isset($topic['group_subject_id']) && $topic['group_subject_id'] !== null
            ? (int) $topic['group_subject_id']
            : null;

        if ($subjectId !== null && $topicSubjectId === $subjectId) {
            return true;
        }

        // Las escuelas suelen usar nombres de materias distintos a los nombres
        // oficiales de los campos NEM (por ejemplo Español vs Lenguajes y
        // Matemáticas vs Saberes y Pensamiento Científico). Si no coincide el
        // ID de materia, permitimos la equivalencia únicamente cuando ambos
        // lados apuntan al mismo campo curricular conocido.
        $topicField = trim((string) ($topic['subject_field_code'] ?? ''));
        if ($topicField === '') {
            $topicField = $this->inferredFieldCode((string) ($topic['subject_name'] ?? '')) ?? '';
        }

        $blockFields = array_values(array_filter(array_map(
            static fn ($code): string => trim((string) $code),
            (array) ($block['field_codes'] ?? []),
        )));
        $blockField = $blockFields[0] ?? null;
        if ($blockField === null || $blockField === '') {
            $blockField = $this->inferredFieldCode(
                (string) ($block['subject_name_snapshot'] ?? $block['label'] ?? ''),
            );
        }

        return $topicField !== '' && $blockField !== null && $topicField === $blockField;
    }

    private function inferredFieldCode(string $subjectName): ?string
    {
        $normalized = mb_strtolower(Str::ascii(trim($subjectName)));
        $normalized = preg_replace('/\s+/', ' ', $normalized) ?? $normalized;

        return match ($normalized) {
            'espanol', 'lenguaje', 'lenguajes', 'lengua materna' => 'LEN',
            'matematica', 'matematicas', 'saberes y pensamiento cientifico' => 'SPC',
            default => null,
        };
    }
}
