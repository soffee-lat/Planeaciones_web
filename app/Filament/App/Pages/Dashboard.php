<?php

namespace App\Filament\App\Pages;

use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\Group;
use App\Models\PlanningRequest;
use App\Models\School;
use App\Services\Commerce\PlanningCommercialPresentation;
use BackedEnum;
use Filament\Support\Icons\Heroicon;

class Dashboard extends \Filament\Pages\Dashboard
{
    protected static string $routePath = 'inicio';
    protected static ?string $title = 'Inicio';
    protected static ?string $navigationLabel = 'Inicio';
    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;
    protected static ?int $navigationSort = 1;
    protected string $view = 'filament.app.pages.dashboard';

    /** @return array<string,mixed> */
    protected function getViewData(): array
    {
        $user = auth()->user();
        $presentation = app(PlanningCommercialPresentation::class);
        $eligibleGroups = PlanningRequestResource::eligibleGroupOptions();

        $recent = PlanningRequest::query()
            ->where('owner_id', $user->id)
            ->with(['group:id,name', 'blocks'])
            ->latest('updated_at')
            ->limit(4)
            ->get()
            ->map(fn (PlanningRequest $request): array => [
                'id' => (int) $request->id,
                'title' => trim((string) ($request->integrative_project ?: $request->project ?: 'Planeación '.$request->id)),
                'group' => (string) ($request->group?->name ?? 'Grupo'),
                'status' => $presentation->status($request),
                'updated' => $request->updated_at?->diffForHumans() ?? '',
                'url' => PlanningRequestResource::getUrl('view', ['record' => $request->id]),
            ])
            ->all();

        return [
            'eligibleGroups' => $eligibleGroups,
            'eligibleGroupCount' => count($eligibleGroups),
            'groupCount' => Group::query()->where('owner_id', $user->id)->whereNull('archived_at')->count(),
            'schoolCount' => School::query()->where('owner_id', $user->id)->count(),
            'planningCount' => PlanningRequest::query()->where('owner_id', $user->id)->count(),
            'recentPlanning' => $recent,
            'planSummary' => $presentation->forCustomer($user),
        ];
    }
}
