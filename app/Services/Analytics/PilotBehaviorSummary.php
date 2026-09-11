<?php

namespace App\Services\Analytics;

use App\Enums\ProductEventType;
use App\Models\PlanningRequest;
use Carbon\CarbonInterface;

final class PilotBehaviorSummary
{
    /**
     * @return list<array<string,int|string|bool|null>>
     */
    public function rows(?int $userId = null, ?int $requestId = null): array
    {
        $requests = PlanningRequest::query()
            ->where('creation_mode', 'quick')
            ->with(['productEvents', 'feedback'])
            ->orderBy('owner_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        $attemptByUser = [];
        $rows = [];

        foreach ($requests as $request) {
            $attemptByUser[$request->owner_id] = ($attemptByUser[$request->owner_id] ?? 0) + 1;
            $attempt = $attemptByUser[$request->owner_id];

            if ($userId !== null && (int) $request->owner_id !== $userId) {
                continue;
            }
            if ($requestId !== null && (int) $request->id !== $requestId) {
                continue;
            }

            $events = $request->productEvents->sortBy('occurred_at')->values();
            $started = $this->firstOccurredAt($events, ProductEventType::PlanningStarted);
            $mapConfirmed = $this->firstOccurredAt($events, ProductEventType::CurriculumMapConfirmed);
            $generated = $this->firstOccurredAt($events, ProductEventType::PlanGenerated);
            $docx = $this->firstOccurredAt($events, ProductEventType::DocxDownloaded);
            $completed = $this->firstOccurredAt($events, ProductEventType::PlanningCompleted);

            $rows[] = [
                'request_id' => (int) $request->id,
                'user_id' => (int) $request->owner_id,
                'attempt' => $attempt,
                'repeat_planning' => $attempt > 1,
                'group_id' => (int) $request->group_id,
                'grade_id' => (int) $request->grade_id,
                'accepted' => $this->count($events, ProductEventType::CurriculumSuggestionAccepted),
                'rejected' => $this->count($events, ProductEventType::CurriculumSuggestionRejected),
                'added' => $this->count($events, ProductEventType::CurriculumSelectionAdded),
                'seconds_to_map' => $this->elapsed($started, $mapConfirmed),
                'seconds_to_generation' => $this->elapsed($started, $generated),
                'seconds_to_docx' => $this->elapsed($started, $docx),
                'completed' => $completed !== null,
                'saved_time_bucket' => $request->feedback?->saved_time_bucket,
                'most_helpful' => $request->feedback?->most_helpful,
                'next_real_planning' => $request->feedback?->next_real_planning,
            ];
        }

        return $rows;
    }

    private function firstOccurredAt($events, ProductEventType $type): ?CarbonInterface
    {
        return $events->first(fn ($event) => $event->event_type === $type)?->occurred_at;
    }

    private function count($events, ProductEventType $type): int
    {
        return $events->filter(fn ($event) => $event->event_type === $type)->count();
    }

    private function elapsed(?CarbonInterface $from, ?CarbonInterface $to): ?int
    {
        if (! $from || ! $to || $to->lt($from)) {
            return null;
        }

        return (int) $from->diffInSeconds($to);
    }
}
