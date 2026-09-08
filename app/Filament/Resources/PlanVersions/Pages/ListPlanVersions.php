<?php

namespace App\Filament\Resources\PlanVersions\Pages;

use App\Filament\Resources\PlanVersions\PlanVersionResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlanVersions extends ListRecords
{
    protected static string $resource = PlanVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
