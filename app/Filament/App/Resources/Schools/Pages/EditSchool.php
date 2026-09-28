<?php

namespace App\Filament\App\Resources\Schools\Pages;

use App\Filament\App\Resources\Schools\SchoolResource;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Validation\ValidationException;

class EditSchool extends EditRecord
{
    protected static string $resource = SchoolResource::class;

    protected function getRedirectUrl(): ?string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getSavedNotificationTitle(): ?string
    {
        return 'Cambios guardados correctamente';
    }

    protected function onValidationError(ValidationException $exception): void
    {
        parent::onValidationError($exception);

        $messages = collect($exception->validator->errors()->all())
            ->map(fn (string $message): string => trim($message))
            ->filter()
            ->unique()
            ->take(5)
            ->values();

        Notification::make()
            ->danger()
            ->title('Te faltan datos para guardar')
            ->body($messages->isEmpty()
                ? 'Revisa los campos marcados antes de guardar.'
                : $messages->implode(' '))
            ->send();
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        // Prevent owner_id tampering via the browser.
        unset($data['owner_id']);

        return $data;
    }
}
