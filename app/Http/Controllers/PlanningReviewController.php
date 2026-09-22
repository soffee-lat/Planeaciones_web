<?php

namespace App\Http\Controllers;

use App\Actions\Planning\AuthorizePlanningRequestForProcessing;
use App\Actions\Planning\ConfirmPlanningRequest;
use App\Enums\PlanningRequestStatus;
use App\Enums\RoleCode;
use App\Exceptions\PlanningCommercialException;
use App\Filament\App\Pages\StartPlanning;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\FormativeField;
use App\Models\PlanningRequest;
use App\Services\Commerce\PlanningCommercialPresentation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

final class PlanningReviewController
{
    public function show(
        Request $httpRequest,
        PlanningRequest $planningRequest,
        PlanningCommercialPresentation $commercial,
    ): View|RedirectResponse {
        $request = $this->ownedEditable($httpRequest, $planningRequest);

        if (! $request->hasConfirmedCurriculumMap()) {
            return redirect()
                ->route('planning.curriculum-map', $request)
                ->withErrors(['curriculum_map' => 'Revisa y confirma primero las conexiones curriculares.']);
        }

        $request->load([
            'group.grade',
            'group.curriculumVersion.curriculum',
            'group.activeSchedule.blocks',
            'planningWeeks.topics.subject',
            'contents.formativeField',
            'pdas.curricularContent.formativeField',
            'articulatingAxes',
            'formatVersion.format',
        ]);

        $requiredCodes = [];
        foreach ($request->group?->activeSchedule?->blocks ?? [] as $block) {
            if (! $block->include_in_planning || $block->is_flexible) {
                continue;
            }

            foreach ((array) ($block->field_codes ?? []) as $code) {
                $code = trim((string) $code);
                if ($code !== '') {
                    $requiredCodes[$code] = true;
                }
            }
        }

        $requiredCodes = array_keys($requiredCodes);
        sort($requiredCodes, SORT_STRING);

        $fieldNames = $requiredCodes === []
            ? []
            : FormativeField::query()
                ->where('curriculum_version_id', $request->curriculum_version_id)
                ->whereIn('code', $requiredCodes)
                ->pluck('name', 'code')
                ->all();

        $selectedFieldCodes = $request->contents
            ->map(fn ($content) => (string) ($content->formativeField?->code ?? ''))
            ->filter()
            ->unique()
            ->values()
            ->all();

        $coverage = array_map(
            fn (string $code): array => [
                'code' => $code,
                'name' => (string) ($fieldNames[$code] ?? $code),
                'covered' => in_array($code, $selectedFieldCodes, true),
            ],
            $requiredCodes,
        );

        return view('planning.review', [
            'request' => $request,
            'coverage' => $coverage,
            'commercial_summary' => $commercial->forCustomer(
                $httpRequest->user(),
                $request->starts_on?->toDateString(),
                $request->ends_on?->toDateString(),
            ),
            'edit_structure_url' => StartPlanning::getUrl() . '?draft=' . $request->id,
            'edit_curriculum_url' => route('planning.curriculum-map', $request),
        ]);
    }

    public function confirm(
        Request $httpRequest,
        PlanningRequest $planningRequest,
        ConfirmPlanningRequest $confirm,
        AuthorizePlanningRequestForProcessing $authorize,
    ): RedirectResponse {
        $request = $this->ownedEditable($httpRequest, $planningRequest);

        if (! $request->hasConfirmedCurriculumMap()) {
            return redirect()
                ->route('planning.curriculum-map', $request)
                ->withErrors(['curriculum_map' => 'Las conexiones curriculares cambiaron. Revísalas y confírmalas nuevamente.']);
        }

        try {
            $confirmed = $confirm->execute($httpRequest->user(), $request);
        } catch (\RuntimeException $error) {
            $code = $error->getMessage();

            if (str_starts_with($code, 'PLANNING_REQUEST_NO_')
                || str_starts_with($code, 'PLANNING_REQUEST_CONTENT_WITHOUT_PDA:')
                || str_starts_with($code, 'PLANNING_REQUEST_SCHEDULE_FIELD_NOT_SELECTED:')) {
                return redirect()
                    ->route('planning.curriculum-map', $request)
                    ->withErrors(['curriculum_map' => 'La selección curricular ya no cumple todos los requisitos. Revísala antes de confirmar la planeación.']);
            }

            return back()->withErrors([
                'planning_review' => 'No pudimos confirmar la planeación. Revisa la información e inténtalo nuevamente.',
            ]);
        }

        $activationMessage = null;
        if ($confirmed->status === PlanningRequestStatus::ESPERANDO_PAGO) {
            try {
                $confirmed = $authorize->execute($httpRequest->user(), $confirmed);
            } catch (PlanningCommercialException $error) {
                $activationMessage = $error->userMessage();
            }
        }

        $url = PlanningRequestResource::getUrl('view', ['record' => $confirmed->id]);

        if ($confirmed->status === PlanningRequestStatus::LISTA_PARA_PROCESAR) {
            return redirect($url)->with(
                'planning_status',
                'Planeación confirmada y activada. Ya puedes iniciar la generación desde el seguimiento.',
            );
        }

        return redirect($url)->with(
            'planning_status',
            'Planeación confirmada. ' . ($activationMessage ?: 'Quedó pendiente de activación antes de iniciar la generación.'),
        );
    }

    private function ownedEditable(Request $httpRequest, PlanningRequest $planningRequest): PlanningRequest
    {
        $user = $httpRequest->user();

        abort_unless(
            $user
            && $user->status === 'active'
            && $user->hasVerifiedEmail()
            && $user->hasRole(RoleCode::Customer),
            403,
        );
        abort_unless((int) $planningRequest->owner_id === (int) $user->id, 404);
        abort_unless($planningRequest->canEditInputs(), 409, 'Esta planeación ya no está disponible para edición.');

        return $planningRequest->refresh();
    }
}
