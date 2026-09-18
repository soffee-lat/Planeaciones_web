<?php

namespace App\Actions\Planning;

use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Planning\PlanningPeriodService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

final class SyncPlanningPedagogicalStructure
{
    public function __construct(private PlanningPeriodService $periods) {}

    /**
     * @param array{
     *   period_type:string,
     *   period_key:string,
     *   integrative_project?:string|null,
     *   integrative_project_purpose?:string|null,
     *   context_note?:string|null,
     *   weeks:array<int,array{sequence:int,topics:array<int,array{topic:string,group_subject_id:int,notes?:string|null}>}>
     * } $input
     */
    public function execute(User $actor, PlanningRequest $request, array $input): PlanningRequest
    {
        Gate::forUser($actor)->authorize('update', $request);
        if (! $request->isDraft()) {
            throw ValidationException::withMessages(['status' => 'La planeación ya no puede editarse.']);
        }

        try {
            $period = $this->periods->resolve(
                trim((string) ($input['period_type'] ?? '')),
                trim((string) ($input['period_key'] ?? '')),
            );
        } catch (\InvalidArgumentException) {
            throw ValidationException::withMessages(['period_key' => 'Selecciona un periodo escolar válido.']);
        }

        $inputWeeks = collect($input['weeks'] ?? [])->keyBy(fn ($week) => (int) ($week['sequence'] ?? 0));
        $canonicalWeeks = [];
        $allTopics = [];
        $subjectIds = [];

        foreach ($period['weeks'] as $week) {
            $weekInput = $inputWeeks->get((int) $week['sequence'], []);
            $topics = [];

            foreach ((array) ($weekInput['topics'] ?? []) as $index => $topicInput) {
                $topic = trim((string) ($topicInput['topic'] ?? ''));
                $subjectId = (int) ($topicInput['group_subject_id'] ?? 0);
                $notes = trim((string) ($topicInput['notes'] ?? ''));

                if ($topic === '' && $subjectId === 0) {
                    continue;
                }
                if (mb_strlen($topic) < 2 || mb_strlen($topic) > 255) {
                    throw ValidationException::withMessages([
                        "weeks.{$week['sequence']}.topics.{$index}.topic" => 'Escribe un tema de entre 2 y 255 caracteres.',
                    ]);
                }
                if ($subjectId < 1) {
                    throw ValidationException::withMessages([
                        "weeks.{$week['sequence']}.topics.{$index}.group_subject_id" => 'Selecciona la materia principal de este tema.',
                    ]);
                }
                if (mb_strlen($notes) > 4000) {
                    throw ValidationException::withMessages([
                        "weeks.{$week['sequence']}.topics.{$index}.notes" => 'Las notas del tema son demasiado largas.',
                    ]);
                }

                $topics[] = [
                    'topic' => $topic,
                    'group_subject_id' => $subjectId,
                    'notes' => $notes === '' ? null : $notes,
                    'sort_order' => count($topics) + 1,
                ];
                $allTopics[] = $topic;
                $subjectIds[] = $subjectId;
            }

            if ($topics === []) {
                throw ValidationException::withMessages([
                    "weeks.{$week['sequence']}.topics" => 'Agrega al menos un tema para ' . $week['label'] . '.',
                ]);
            }

            $canonicalWeeks[] = [...$week, 'topics' => $topics];
        }

        $request->loadMissing('group');
        $validSubjects = $request->group->subjects()
            ->where('is_active', true)
            ->whereIn('id', array_values(array_unique($subjectIds)))
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach (array_values(array_unique($subjectIds)) as $subjectId) {
            if (! in_array($subjectId, $validSubjects, true)) {
                throw ValidationException::withMessages([
                    'weeks' => 'Una de las materias seleccionadas no pertenece a este grupo.',
                ]);
            }
        }

        $integrativeProject = trim((string) ($input['integrative_project'] ?? ''));
        $integrativePurpose = trim((string) ($input['integrative_project_purpose'] ?? ''));
        $contextNote = trim((string) ($input['context_note'] ?? ''));

        if (mb_strlen($integrativeProject) > 255) {
            throw ValidationException::withMessages(['integrative_project' => 'El nombre del proyecto integrador es demasiado largo.']);
        }
        if (mb_strlen($integrativePurpose) > 8000) {
            throw ValidationException::withMessages(['integrative_project_purpose' => 'El propósito del proyecto es demasiado largo.']);
        }
        if (mb_strlen($contextNote) > 8000) {
            throw ValidationException::withMessages(['context_note' => 'Las observaciones son demasiado largas.']);
        }

        $topicSummary = implode(' · ', array_values(array_unique($allTopics)));
        $project = $integrativeProject !== '' ? $integrativeProject : ($allTopics[0] ?? 'Planeación');
        $topic = "Temas por semana: {$topicSummary}";
        if ($integrativePurpose !== '') {
            $topic .= "\nProyecto integrador: {$integrativePurpose}";
        }
        if ($contextNote !== '') {
            $topic .= "\nObservaciones: {$contextNote}";
        }

        return DB::transaction(function () use (
            $request,
            $period,
            $canonicalWeeks,
            $integrativeProject,
            $integrativePurpose,
            $project,
            $topic,
            $contextNote,
        ): PlanningRequest {
            $fresh = PlanningRequest::query()->lockForUpdate()->findOrFail($request->id);
            if ($fresh->status !== PlanningRequestStatus::BORRADOR) {
                throw ValidationException::withMessages(['status' => 'La planeación ya no está en borrador.']);
            }

            $before = $this->fingerprint($fresh);
            $fresh->planningWeeks()->delete();

            foreach ($canonicalWeeks as $week) {
                $weekModel = $fresh->planningWeeks()->create([
                    'sequence' => $week['sequence'],
                    'starts_on' => $week['starts_on'],
                    'ends_on' => $week['ends_on'],
                    'label' => $week['label'],
                ]);
                $weekModel->topics()->createMany($week['topics']);
            }

            $fresh->forceFill([
                'period_type' => $period['period_type'],
                'period_key' => $period['period_key'],
                'integrative_project' => $integrativeProject === '' ? null : $integrativeProject,
                'integrative_project_purpose' => $integrativePurpose === '' ? null : $integrativePurpose,
                'starts_on' => $period['starts_on'],
                'ends_on' => $period['ends_on'],
                'period_label' => $period['label'],
                'project' => mb_strimwidth($project, 0, 255, ''),
                'topic' => $topic,
                'comments' => $contextNote === '' ? null : $contextNote,
            ])->save();

            $fresh->load('planningWeeks.topics');
            $after = $this->fingerprint($fresh);

            if ($before !== $after) {
                $fresh->forceFill([
                    'input_revision' => (int) $fresh->input_revision + 1,
                    'curriculum_confirmed_at' => null,
                    'curriculum_selection_fingerprint' => null,
                ])->save();
            }

            return $fresh->refresh()->load('planningWeeks.topics.subject');
        }, attempts: 3);
    }

    private function fingerprint(PlanningRequest $request): string
    {
        $request->loadMissing('planningWeeks.topics');

        $payload = [
            'period_type' => $request->period_type,
            'period_key' => $request->period_key,
            'integrative_project' => $request->integrative_project,
            'integrative_project_purpose' => $request->integrative_project_purpose,
            'weeks' => $request->planningWeeks->map(fn ($week) => [
                'sequence' => (int) $week->sequence,
                'starts_on' => $week->starts_on?->toDateString(),
                'ends_on' => $week->ends_on?->toDateString(),
                'topics' => $week->topics->map(fn ($topic) => [
                    'group_subject_id' => (int) $topic->group_subject_id,
                    'topic' => (string) $topic->topic,
                    'notes' => $topic->notes,
                ])->values()->all(),
            ])->values()->all(),
        ];

        return hash('sha256', json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
