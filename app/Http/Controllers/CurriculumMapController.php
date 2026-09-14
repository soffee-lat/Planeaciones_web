<?php

namespace App\Http\Controllers;

use App\Actions\Planning\AuthorizePlanningRequestForProcessing;
use App\Actions\Planning\ConfirmPlanningRequest;
use App\Enums\RoleCode;
use App\Exceptions\PlanningCommercialException;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use App\Services\Planning\CurriculumMapService;
use Filament\Notifications\Notification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class CurriculumMapController
{
    public function show(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): View
    {
        $request = $this->ownedDraft($httpRequest, $planningRequest);
        $state = $maps->state($httpRequest->user(), $request, true);

        return view('planning.curriculum-map', $state);
    }

    public function decision(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedDraft($httpRequest, $planningRequest);
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
        $request = $this->ownedDraft($httpRequest, $planningRequest);
        $maps->acceptAllSuggested($httpRequest->user(), $request);

        return back()->with('curriculum_map_status', 'Incluimos todas las sugerencias pendientes. Puedes quitar las que no correspondan o continuar.');
    }

    public function add(Request $httpRequest, PlanningRequest $planningRequest, CurriculumMapService $maps): RedirectResponse
    {
        $request = $this->ownedDraft($httpRequest, $planningRequest);
        $data = $httpRequest->validate([
            // Compatibilidad con los formularios unitarios existentes.
            'entity_type' => ['nullable', 'string', 'in:content,pda,axis'],
            'entity_id' => ['nullable', 'integer', 'min:1'],
            // Nuevo flujo por lote: el docente marca todo y envía una sola vez.
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
        $request = $this->ownedDraft($httpRequest, $planningRequest);
        $user = $httpRequest->user();

        try {
            // Paso 2: congelar las conexiones curriculares.
            $mapped = $maps->confirm($user, $request);
            // Paso 3: confirmar inmediatamente la solicitud. Ya no obligamos al
            // docente a recorrer de nuevo el wizard con los mismos datos.
            $confirmed = app(ConfirmPlanningRequest::class)->execute($user, $mapped);
        } catch (\RuntimeException $e) {
            return back()->withErrors(['curriculum_map' => $this->messageFor($e->getMessage())]);
        }

        try {
            app(AuthorizePlanningRequestForProcessing::class)->execute($user, $confirmed);
            Notification::make()
                ->success()
                ->title('Conexiones confirmadas')
                ->body('La planeación quedó lista para su generación.')->send();
        } catch (PlanningCommercialException $e) {
            Notification::make()
                ->warning()
                ->title('Planeación confirmada')
                ->body($e->userMessage())->send();
        } catch (\Throwable $e) {
            report($e);
            Notification::make()
                ->warning()
                ->title('Planeación confirmada')
                ->body('La selección quedó guardada. No pudimos activar el procesamiento ahora; podrás reintentarlo desde el seguimiento.')->send();
        }

        return redirect(PlanningRequestResource::getUrl('view', ['record' => $confirmed->id]));
    }

    private function ownedDraft(Request $httpRequest, PlanningRequest $planningRequest): PlanningRequest
    {
        $user = $httpRequest->user();
        abort_unless($user && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Customer), 403);
        abort_unless((int) $planningRequest->owner_id === (int) $user->id, 404);
        abort_unless($planningRequest->isDraft(), 409, 'El mapa curricular sólo puede editarse mientras la planeación está en borrador.');

        return $planningRequest;
    }

    private function messageFor(string $code): string
    {
        return match (true) {
            $code === 'CURRICULUM_MAP_HAS_PENDING_DECISIONS' => 'Todavía hay sugerencias sin resolver. Usa “Incluir todas” o decide cuáles conservar antes de continuar.',
            $code === 'CURRICULUM_MAP_CONTENT_REQUIRED' => 'Selecciona al menos un contenido o un PDA del catálogo.',
            $code === 'CURRICULUM_MAP_PDA_REQUIRED' => 'Selecciona al menos un PDA. Si eliges un PDA agregaremos automáticamente su contenido relacionado.',
            str_starts_with($code, 'CURRICULUM_MAP_CONTENT_WITHOUT_PDA:') => 'Cada contenido seleccionado debe conservar al menos un PDA relacionado.',
            str_starts_with($code, 'CURRICULUM_MAP_PDA_WITHOUT_CONTENT:') => 'Cada PDA incluido debe pertenecer a uno de los contenidos del mapa.',
            $code === 'CURRICULUM_MAP_ENTITY_NOT_COMPATIBLE' => 'Esa opción no pertenece al currículo y grado de esta planeación.',
            $code === 'CURRICULUM_MAP_ENTITY_NOT_AVAILABLE' => 'Esa sugerencia ya no está disponible en el mapa actual.',
            $code === 'PLANNING_REQUEST_FORMAT_REQUIRED' => 'Selecciona un formato de salida antes de continuar.',
            $code === 'PLANNING_REQUEST_FORMAT_NOT_USABLE' => 'El formato seleccionado ya no está disponible. Vuelve a Nueva planeación y elige otro.',
            default => 'No pudimos aplicar ese cambio. Revisa la selección e inténtalo de nuevo.',
        };
    }
}
