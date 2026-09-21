<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use App\Services\Planning\CurriculumMapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CurriculumMapController
{
    public function show(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): View
    {
        $request = $this->ownedEditable($httpRequest, $planningRequest);
        $state = $maps->state($httpRequest->user(), $request, true);

        return view('planning.curriculum-map', $state);
    }

    public function decision(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedEditable($httpRequest, $planningRequest);
        $data = $httpRequest->validate([
            'entity_type' => ['required', 'string', 'in:content,pda,axis'],
            'entity_id' => ['required', 'integer', 'min:1'],
            'decision' => ['required', 'string', 'in:accept,reject'],
        ]);

        try {
            $maps->decide(
                $httpRequest->user(),
                $request,
                $data['entity_type'],
                (int) $data['entity_id'],
                $data['decision'] === 'accept',
            );
        } catch (\RuntimeException $e) {
            return back()->withErrors(['curriculum_map' => $this->messageFor($e->getMessage())]);
        }

        return back();
    }

    public function acceptAll(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedEditable($httpRequest, $planningRequest);
        $maps->acceptAllSuggested($httpRequest->user(), $request);

        return back()->with('curriculum_map_status', 'Aceptamos las sugerencias pendientes. Puedes quitar o agregar lo que necesites antes de confirmar.');
    }

    public function add(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedEditable($httpRequest, $planningRequest);
        $data = $httpRequest->validate([
            'entity_type' => ['required', 'string', 'in:content,pda,axis'],
            'entity_id' => ['required', 'integer', 'min:1'],
        ]);

        try {
            $maps->add($httpRequest->user(), $request, $data['entity_type'], (int) $data['entity_id']);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['curriculum_map' => $this->messageFor($e->getMessage())]);
        }

        return back()->with('curriculum_map_status', 'Agregamos tu selección al mapa curricular.');
    }

    public function confirm(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedEditable($httpRequest, $planningRequest);

        try {
            $confirmed = $maps->confirm($httpRequest->user(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['curriculum_map' => $this->messageFor($e->getMessage())]);
        }

        $url = PlanningRequestResource::getUrl('edit', ['record' => $confirmed]);

        return redirect($url . '?paso=4')->with(
            'curriculum_map_status',
            'Mapa curricular confirmado. Ya puedes revisar el resumen de la planeación.',
        );
    }

    private function ownedEditable(Request $httpRequest, PlanningRequest $planningRequest): PlanningRequest
    {
        $user = $httpRequest->user();
        abort_unless($user && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Customer), 403);
        abort_unless((int) $planningRequest->owner_id === (int) $user->id, 404);
        abort_unless($planningRequest->canEditInputs(), 409, 'Esta planeación no está disponible para revisar sus insumos curriculares.');

        return $planningRequest;
    }

    private function messageFor(string $code): string
    {
        return match (true) {
            $code === 'CURRICULUM_MAP_HAS_PENDING_DECISIONS' => 'Todavía hay sugerencias sin aceptar o rechazar. Puedes usar “Aceptar todas” y después ajustar lo que no quieras.',
            $code === 'CURRICULUM_MAP_CONTENT_REQUIRED' => 'El mapa necesita al menos un contenido.',
            $code === 'CURRICULUM_MAP_PDA_REQUIRED' => 'El mapa necesita al menos un PDA.',
            str_starts_with($code, 'CURRICULUM_MAP_CONTENT_WITHOUT_PDA:') => 'Cada contenido seleccionado debe conservar al menos un PDA relacionado.',
            str_starts_with($code, 'CURRICULUM_MAP_PDA_WITHOUT_CONTENT:') => 'Cada PDA incluido debe pertenecer a uno de los contenidos del mapa.',
            str_starts_with($code, 'CURRICULUM_MAP_SCHEDULE_FIELD_REQUIRED:') => 'Tu horario tiene campos oficiales sin cobertura curricular en esta planeación (' . str_replace(',', ', ', substr($code, strlen('CURRICULUM_MAP_SCHEDULE_FIELD_REQUIRED:'))) . '). Agrega al menos un contenido y su PDA de cada campo antes de confirmar.',
            $code === 'CURRICULUM_MAP_ENTITY_NOT_COMPATIBLE' => 'Esa opción no pertenece al currículo y grado de esta planeación.',
            $code === 'CURRICULUM_MAP_ENTITY_NOT_AVAILABLE' => 'Esa sugerencia ya no está disponible en el mapa actual.',
            default => 'No pudimos aplicar ese cambio al mapa curricular. Recarga la página e inténtalo de nuevo.',
        };
    }
}
