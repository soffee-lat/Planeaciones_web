<?php

namespace App\Filament\App\Resources\Groups\Pages;

use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\Group;
use App\Models\School;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;

class EditGroup extends EditRecord
{
    protected static string $resource = GroupResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('schedule')
                ->label('Horario')
                ->icon('heroicon-o-calendar-days')
                ->color('primary')
                ->url(fn (): string => GroupResource::getUrl('schedule', ['record' => $this->getRecord()->getKey()])),
        ];
    }

    protected function resolveRecord(int|string $key): \Illuminate\Database\Eloquent\Model
    {
        // Extra defensive: never let a request return another user's group.
        return Group::query()->where('owner_id', auth()->id())->findOrFail($key);
    }

    protected function mutateFormDataBeforeSave(array $data): array
    {
        unset($data['owner_id']);

        if (array_key_exists('school_id', $data)) {
            $ownerOk = School::query()
                ->where('id', $data['school_id'])
                ->where('owner_id', auth()->id())
                ->exists();
            if (! $ownerOk) {
                throw ValidationException::withMessages(['school_id' => 'Esa escuela no pertenece a tu cuenta.']);
            }
        }

        return $data;
    }

    protected function handleRecordUpdate(\Illuminate\Database\Eloquent\Model $record, array $data): \Illuminate\Database\Eloquent\Model
    {
        /** @var Group $record */
        $profileData = Arr::pull($data, 'profile', []);
        $record->fill($data)->save();

        $profile = $record->profile()->firstOrCreate([], ['revision' => 0]);
        if (! empty($profileData)) {
            app(UpdateGroupProfile::class)->execute(auth()->user(), $profile, $profileData);
        }

        return $record->refresh();
    }
}
