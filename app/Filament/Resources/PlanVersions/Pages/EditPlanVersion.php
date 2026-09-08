<?php

namespace App\Filament\Resources\PlanVersions\Pages;

use App\Filament\Resources\PlanVersions\PlanVersionResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlanVersion extends EditRecord
{
    protected static string $resource = PlanVersionResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
