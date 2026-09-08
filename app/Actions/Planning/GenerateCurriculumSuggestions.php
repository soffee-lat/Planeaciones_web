<?php

namespace App\Actions\Planning;

use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Planning\CurriculumSuggestionService;
use Illuminate\Support\Facades\Gate;

/**
 * Acción autorizada que ejecuta CurriculumSuggestionService sobre un borrador.
 * No persiste ni escribe pivotes; sólo devuelve IDs candidatos para que el
 * docente confirme, ajuste o descarte en la UI.
 */
class GenerateCurriculumSuggestions
{
    public function __construct(private CurriculumSuggestionService $service) {}

    /**
     * @param  array{project?:?string,topic?:?string,book_pages?:?string,special_events?:?string,comments?:?string,required_activities?:?string}  $overrides
     * @return array{strategy_version:string,tokens:array<int,string>,content_ids:array<int,int>,pda_ids:array<int,int>,axis_ids:array<int,int>,formative_field_ids:array<int,int>,has_strong_match:bool,reasons:array<int,string>}
     */
    public function execute(User $actor, PlanningRequest $request, array $overrides = []): array
    {
        Gate::forUser($actor)->authorize('update', $request);
        if (! $request->isDraft()) {
            throw new \RuntimeException('PLANNING_REQUEST_ALREADY_CONFIRMED');
        }
        $input = array_merge([
            'project' => $request->project,
            'topic' => $request->topic,
            'book_pages' => $request->book_pages,
            'required_activities' => $request->required_activities,
            'special_events' => $request->special_events,
            'comments' => $request->comments,
        ], array_filter($overrides, fn ($v) => $v !== null));

        return $this->service->suggest(
            (int) $request->curriculum_version_id,
            (int) $request->grade_id,
            $input,
        );
    }
}
