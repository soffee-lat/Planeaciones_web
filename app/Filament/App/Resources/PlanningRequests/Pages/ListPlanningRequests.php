<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanningRequests extends ListRecords
{
    protected static string $resource = PlanningRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nueva planeación')];
    }
}
