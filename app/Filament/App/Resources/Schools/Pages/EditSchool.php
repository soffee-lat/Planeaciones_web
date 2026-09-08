<?php

namespace App\Filament\App\Resources\Schools\Pages;

use App\Filament\App\Resources\Schools\SchoolResource;
use Filament\Resources\Pages\EditRecord;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Prevent owner_id tampering via the browser.
        unset($data['owner_id']);

        return $data;
    }
}
