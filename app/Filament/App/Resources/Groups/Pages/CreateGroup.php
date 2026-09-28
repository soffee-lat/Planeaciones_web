<?php

namespace App\Filament\App\Resources\Groups\Pages;

use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\Group;
use App\Models\School;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class CreateGroup extends CreateRecord
{
    protected static string $resource = GroupResource::class;

    protected static bool $canCreateAnother = false;

    protected function getRedirectUrl(): string
    {
        return static::getResource()::getUrl('index');
    }

    protected function getCreatedNotificationTitle(): ?string
    {
        return 'Grupo guardado correctamente';
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

    protected function mutateFormDataBeforeCreate(array $data): array
    {
        $data['owner_id'] = auth()->id();

        // Server-side check: school MUST belong to the authenticated user.
        $ownerOk = School::query()
            ->where('id', $data['school_id'] ?? null)
            ->where('owner_id', auth()->id())
            ->exists();
        if (! $ownerOk) {
            throw ValidationException::withMessages(['school_id' => 'Esa escuela no pertenece a tu cuenta.']);
        }

        return $data;
    }

    protected function handleRecordCreation(array $data): Group
    {
        $profileData = Arr::pull($data, 'profile', []);
        /** @var Group $group */
        $group = static::getModel()::create($data);
        $profile = $group->profile()->create(['revision' => 0]);
        if (! empty($profileData)) {
            app(UpdateGroupProfile::class)->execute(auth()->user(), $profile, $profileData);
        }

        return $group;
    }
}
