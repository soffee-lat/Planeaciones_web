<?php

namespace App\Filament\App\Resources\Groups\Pages;

use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Filament\App\Resources\Groups\GroupResource;
use App\Models\Group;
use App\Models\InstitutionalFormat;
use App\Models\School;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
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
            Action::make('preferredFormat')
                ->label('Formato de planeación')
                ->icon('heroicon-o-document-text')
                ->schema([
                    Select::make('preferred_format_id')
                        ->label('Formato que usa este grupo')
                        ->options(fn (): array => InstitutionalFormat::query()
                            ->where('kind', InstitutionalFormatKind::Institutional->value)
                            ->where('status', InstitutionalFormatStatus::Ready->value)
                            ->where(function ($query): void {
                                $query->whereNull('owner_id')->orWhere('owner_id', auth()->id());
                            })
                            ->whereHas('versions', fn ($query) => $query->whereNotNull('published_at'))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->default(fn () => $this->getRecord()->profile?->preferred_format_id)
                        ->placeholder('Usar formato estándar')
                        ->searchable()
                        ->native(false)
                        ->helperText('Planeaciones analizará este formato antes de generar contenido y llenará sus campos específicos.'),
                ])
                ->action(function (array $data): void {
                    /** @var Group $group */
                    $group = $this->getRecord();
                    $formatId = $data['preferred_format_id'] ?? null;

                    if ($formatId !== null) {
                        $allowed = InstitutionalFormat::query()
                            ->whereKey($formatId)
                            ->where('kind', InstitutionalFormatKind::Institutional->value)
                            ->where('status', InstitutionalFormatStatus::Ready->value)
                            ->where(function ($query): void {
                                $query->whereNull('owner_id')->orWhere('owner_id', auth()->id());
                            })
                            ->whereHas('versions', fn ($query) => $query->whereNotNull('published_at'))
                            ->exists();
                        if (! $allowed) {
                            throw ValidationException::withMessages([
                                'preferred_format_id' => 'Ese formato no está disponible para tu cuenta.',
                            ]);
                        }
                    }

                    $profile = $group->profile()->firstOrCreate([], ['revision' => 0]);
                    app(UpdateGroupProfile::class)->execute(
                        auth()->user(),
                        $profile,
                        ['preferred_format_id' => $formatId === null ? null : (int) $formatId],
                    );
                    $this->record = $group->fresh(['profile']);

                    Notification::make()
                        ->success()
                        ->title('Formato del grupo actualizado')
                        ->body($formatId === null
                            ? 'Las siguientes planeaciones usarán el formato estándar.'
                            : 'Las siguientes planeaciones tendrán en cuenta los campos y estructura de este formato.')
                        ->send();
                }),
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
