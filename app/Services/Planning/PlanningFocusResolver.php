<?php

namespace App\Services\Planning;

use Illuminate\Support\Collection;

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
            ])->values();

            $blocks = array_map(function (array $block) use ($week, $topics): array {
                $subjectId = isset($block['group_subject_id']) && $block['group_subject_id'] !== null
                    ? (int) $block['group_subject_id']
                    : null;

                $primary = $subjectId
                    ? $topics->where('group_subject_id', $subjectId)->values()
                    : collect();

                $transversalCandidates = $topics
                    ->reject(fn (array $topic) => $subjectId !== null && (int) ($topic['group_subject_id'] ?? 0) === $subjectId)
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
}
