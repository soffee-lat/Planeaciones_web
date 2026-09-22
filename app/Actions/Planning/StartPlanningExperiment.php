<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Enums\ProductEventType;
use App\Enums\RoleCode;
use App\Models\Group;
use App\Models\GroupProfile;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Analytics\ProductEventRecorder;
use App\Services\Documents\PlanningFormatResolver;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

final class StartPlanningExperiment
{
    public function __construct(private ProductEventRecorder $events) {}

    public function execute(
        User $actor,
        int $groupId,
        string $startsOn,
        string $endsOn,
        string $workFocus,
        ?string $contextNote = null,
        ?array $pedagogicalStructure = null,
        ?int $formatVersionId = null,
    ): PlanningRequest {
        $this->assertActor($actor);

        $group = Group::query()
            ->with(['profile', 'curriculumVersion'])
            ->where('owner_id', $actor->id)
            ->whereNull('archived_at')
            ->whereHas('profile', function ($query): void {
                foreach (GroupProfile::REQUIRED_FOR_COMPLETENESS as $column) {
                    $query->whereNotNull($column);
                }
            })
            ->whereHas('curriculumVersion', fn ($query) => $query->whereNotNull('published_at'))
            ->find($groupId);

        if (! $group) {
            throw new \RuntimeException('PLANNING_EXPERIMENT_GROUP_NOT_ELIGIBLE');
        }

        try {
            $start = CarbonImmutable::parse($startsOn)->startOfDay();
            $end = CarbonImmutable::parse($endsOn)->startOfDay();
        } catch (\Throwable) {
            throw new \RuntimeException('PLANNING_EXPERIMENT_DATE_INVALID');
        }
        if ($end->lt($start)) {
            throw new \RuntimeException('PLANNING_EXPERIMENT_DATE_RANGE_INVALID');
        }

        $workFocus = trim($workFocus);
        $contextNote = trim((string) $contextNote);
        if (mb_strlen($workFocus) < 3 || mb_strlen($workFocus) > 255) {
            throw new \RuntimeException('PLANNING_EXPERIMENT_FOCUS_INVALID');
        }
        if (mb_strlen($contextNote) > 8000) {
            throw new \RuntimeException('PLANNING_EXPERIMENT_CONTEXT_TOO_LONG');
        }

        return DB::transaction(function () use ($actor, $group, $start, $end, $workFocus, $contextNote, $pedagogicalStructure, $formatVersionId): PlanningRequest {
            $request = PlanningRequest::query()->create([
                'owner_id' => $actor->id,
                'group_id' => $group->id,
                'curriculum_version_id' => $group->curriculum_version_id,
                'grade_id' => $group->grade_id,
                'creation_mode' => 'quick',
                'starts_on' => $start->toDateString(),
                'ends_on' => $end->toDateString(),
                'project' => $workFocus,
                'topic' => $contextNote === '' ? null : $contextNote,
                'format_version_id' => $formatVersionId,
                'status' => PlanningRequestStatus::BORRADOR->value,
            ]);

            if ($formatVersionId !== null) {
                // La elección de formato es sólo de exportación. Se valida aquí
                // contra las mismas reglas del renderer, pero no forma parte del
                // snapshot pedagógico ni modifica la generación canónica.
                $resolvedFormat = app(PlanningFormatResolver::class)->resolve($request);
                if ((int) $resolvedFormat->id !== (int) $formatVersionId) {
                    throw new \RuntimeException('PLANNING_EXPERIMENT_FORMAT_NOT_USABLE');
                }
            }

            if ($pedagogicalStructure !== null) {
                $request = app(SyncPlanningPedagogicalStructure::class)->execute(
                    $actor,
                    $request,
                    $pedagogicalStructure,
                );
            }

            $this->events->record(
                $actor,
                ProductEventType::PlanningStarted,
                request: $request,
                metadata: [
                    'entry_surface' => 'curricular_validation_v1',
                    'profile_reused' => true,
                    'session_minutes_known' => $group->profile?->session_minutes !== null,
                    'period_type' => $request->period_type,
                    'structured_topics' => $pedagogicalStructure !== null,
                ],
            );

            return $request->fresh(['group.profile', 'grade', 'curriculumVersion', 'planningWeeks.topics.subject']) ?? $request;
        }, attempts: 3);
    }

    private function assertActor(User $actor): void
    {
        if ($actor->status !== 'active' || ! $actor->hasVerifiedEmail() || ! $actor->hasRole(RoleCode::Customer)) {
            throw new \RuntimeException('PLANNING_EXPERIMENT_ACTOR_REQUIRED');
        }
    }
}
