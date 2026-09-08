<?php

namespace App\Filament\App\Resources\Schools\Pages;

use App\Filament\App\Resources\Schools\SchoolResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListSchools extends ListRecords
{
    protected static string $resource = SchoolResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()->label('Nueva escuela')];
    }
}
