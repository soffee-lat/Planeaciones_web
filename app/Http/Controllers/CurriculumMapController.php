<?php

namespace App\Http\Controllers;

use App\Enums\RoleCode;
use App\Models\PlanningRequest;
use App\Services\Planning\CurriculumMapService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'entity_type' => ['nullable', 'string', 'in:content,pda,axis'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            'content_ids' => ['nullable', 'array'],
            'content_ids.*' => ['integer', 'min:1'],
            'pda_ids' => ['nullable', 'array'],
            'pda_ids.*' => ['integer', 'min:1'],
            'axis_ids' => ['nullable', 'array'],
            'axis_ids.*' => ['integer', 'min:1'],
        ]);

        $selection = [
            'content' => array_values(array_unique(array_map('intval', $data['content_ids'] ?? []))),
            'pda' => array_values(array_unique(array_map('intval', $data['pda_ids'] ?? []))),
            'axis' => array_values(array_unique(array_map('intval', $data['axis_ids'] ?? []))),
        ];

        if (! empty($data['entity_type']) && ! empty($data['entity_id'])) {
            $selection[$data['entity_type']][] = (int) $data['entity_id'];
            $selection[$data['entity_type']] = array_values(array_unique($selection[$data['entity_type']]));
        }

        $count = count($selection['content']) + count($selection['pda']) + count($selection['axis']);
        if ($count === 0) {
            return back()->withErrors(['curriculum_map' => 'Selecciona al menos una opción del catálogo antes de agregar.']);
        }

        try {
            DB::transaction(function () use ($maps, $httpRequest, $request, $selection): void {
                foreach (['content', 'pda', 'axis'] as $type) {
                    foreach ($selection[$type] as $id) {
                        $maps->add($httpRequest->user(), $request, $type, $id);
                    }
                }
            }, attempts: 3);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['curriculum_map' => $this->messageFor($e->getMessage())]);
        }

        return back()->with(
            'curriculum_map_status',
            $count === 1
                ? 'Agregamos tu selección al mapa curricular.'
                : "Agregamos {$count} selecciones al mapa curricular en un solo paso.",
        );
    }

    public function confirm(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedEditable($httpRequest, $planningRequest);

        try {
            $confirmed = $maps->confirm($httpRequest->user(), $request);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['curriculum_map' => $this->messageFor($e->getMessage())]);
        }

        return redirect()
            ->route('planning.review', $confirmed)
            ->with(
                'curriculum_map_status',
                'Conexiones curriculares confirmadas. Revisa el resumen final antes de confirmar la planeación.',
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
