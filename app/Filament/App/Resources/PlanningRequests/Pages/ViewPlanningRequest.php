<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;

class ViewPlanningRequest extends ViewRecord
{
    protected static string $resource = PlanningRequestResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return PlanningRequest::query()->where('owner_id', auth()->id())->findOrFail($key);
    }
}
