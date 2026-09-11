<?php

namespace App\Actions\Validation;

use App\Enums\ProductEventType;
use App\Models\PlanningFeedback;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Analytics\ProductEventRecorder;
use Illuminate\Support\Facades\DB;

final class SubmitPilotFeedback
{
    public const SAVED_TIME_OPTIONS = [
        'none' => 'No me ahorró tiempo',
        'under_30' => 'Menos de 30 minutos',
        '30_60' => 'Entre 30 y 60 minutos',
        '60_120' => 'Entre 1 y 2 horas',
        'over_120' => 'Más de 2 horas',
    ];

    public const MOST_HELPFUL_OPTIONS = [
        'curriculum' => 'Encontrar contenidos y PDA',
        'connections' => 'Conectar campos y ejes',
        'projects_materials' => 'Proyectos y materiales',
        'activities' => 'Actividades',
        'assessment' => 'Evaluación',
        'group_adaptation' => 'Adaptar al grupo',
        'institutional_format' => 'Llenar mi formato',
        'other' => 'Otro',
    ];

    public const NEXT_PLANNING_OPTIONS = [
        'yes' => 'Sí',
        'maybe' => 'Tal vez',
        'no' => 'No',
    ];

    public function __construct(private ProductEventRecorder $events) {}

    public function execute(
        User $actor,
        PlanningRequest $request,
        string $savedTimeBucket,
        string $mostHelpful,
        string $nextRealPlanning,
    ): PlanningFeedback {
        $this->assertOption($savedTimeBucket, self::SAVED_TIME_OPTIONS, 'PILOT_FEEDBACK_SAVED_TIME_INVALID');
        $this->assertOption($mostHelpful, self::MOST_HELPFUL_OPTIONS, 'PILOT_FEEDBACK_HELPFUL_INVALID');
        $this->assertOption($nextRealPlanning, self::NEXT_PLANNING_OPTIONS, 'PILOT_FEEDBACK_NEXT_INVALID');

        return DB::transaction(function () use ($actor, $request, $savedTimeBucket, $mostHelpful, $nextRealPlanning): PlanningFeedback {
            $fresh = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ((int) $fresh->owner_id !== (int) $actor->id || $fresh->creation_mode !== 'quick') {
                throw new \RuntimeException('PILOT_FEEDBACK_REQUEST_NOT_AVAILABLE');
            }

            $delivery = $fresh->deliveries()->latest('id')->lockForUpdate()->first();
            if (! $delivery) {
                throw new \RuntimeException('PILOT_FEEDBACK_DELIVERY_REQUIRED');
            }

            $existing = PlanningFeedback::query()
                ->where('planning_request_id', $fresh->id)
                ->lockForUpdate()
                ->first();
            if ($existing) {
                if ($existing->saved_time_bucket === $savedTimeBucket
                    && $existing->most_helpful === $mostHelpful
                    && $existing->next_real_planning === $nextRealPlanning) {
                    return $existing;
                }
                throw new \RuntimeException('PILOT_FEEDBACK_ALREADY_SUBMITTED');
            }

            $feedback = PlanningFeedback::query()->create([
                'planning_request_id' => $fresh->id,
                'user_id' => $actor->id,
                'delivery_id' => $delivery->id,
                'saved_time_bucket' => $savedTimeBucket,
                'most_helpful' => $mostHelpful,
                'next_real_planning' => $nextRealPlanning,
                'submitted_at' => now(),
            ]);

            $this->events->record(
                $actor,
                ProductEventType::PlanningCompleted,
                request: $fresh,
                metadata: [
                    'delivery_id' => (int) $delivery->id,
                    'feedback_submitted' => true,
                ],
            );

            return $feedback->fresh(['planningRequest', 'delivery']);
        }, attempts: 3);
    }

    /** @param array<string,string> $options */
    private function assertOption(string $value, array $options, string $error): void
    {
        if (! array_key_exists($value, $options)) {
            throw new \RuntimeException($error);
        }
    }
}
