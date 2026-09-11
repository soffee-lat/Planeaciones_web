<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Filament\App\Pages\StartPlanning;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use Filament\Actions\Action;
use Filament\Resources\Pages\ListRecords;

class ListPlanningRequests extends ListRecords
{
    protected static string $resource = PlanningRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startPlanning')
                ->label('Nueva planeación')
                ->icon('heroicon-o-document-plus')
                ->url(StartPlanning::getUrl()),
        ];
    }
}
