<?php

namespace App\Filament\App\Resources\Schools\Pages;

use App\Filament\App\Resources\Schools\SchoolResource;
use Filament\Resources\Pages\CreateRecord;

class CreateSchool extends CreateRecord
{
    protected static string $resource = SchoolResource::class;

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // NEVER trust owner_id from the browser. Always derive from the authenticated user.
        $data['owner_id'] = auth()->id();

        return $data;
    }
}
