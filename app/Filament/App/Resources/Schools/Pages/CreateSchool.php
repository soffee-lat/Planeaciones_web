<?php

namespace App\Filament\App\Resources\Schools\Pages;

use App\Filament\App\Resources\Schools\SchoolResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Validation\ValidationException;

class CreateSchool extends CreateRecord
{
    protected static string $resource = SchoolResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Escuela guardada correctamente';
    }

    protected function onValidationError(ValidationException $exception): void
    {
        parent::onValidationError($exception);

        Notification::make()
            ->danger()
            ->title('Faltan campos obligatorios')
            ->body('Revisa los campos marcados antes de guardar.')
            ->send();
    }

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        // NEVER trust owner_id from the browser. Always derive from the authenticated user.
        $data['owner_id'] = auth()->id();

        return $data;
    }
}
