<?php

namespace App\Filament\Resources\InstitutionalFormats\Pages;

use App\Actions\Documents\CreateInstitutionalFormatDraft;
use App\Enums\RoleCode;
use App\Filament\Resources\InstitutionalFormats\InstitutionalFormatResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ListRecords;
use Illuminate\Support\Facades\Storage;

class ListInstitutionalFormats extends ListRecords
{
    protected static string $resource = InstitutionalFormatResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('createInstitutionalFormat')
                ->label('Nuevo formato institucional')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    Select::make('owner_id')
                        ->label('Docente propietario')
                        ->options(fn (): array => User::query()
                            ->where('status', 'active')
                            ->whereHas('roles', fn ($query) => $query->where('code', RoleCode::Customer->value))
                            ->orderBy('name')
                            ->pluck('name', 'id')
                            ->all())
                        ->searchable()
                        ->required(),
                    TextInput::make('name')
                        ->label('Nombre del formato')
                        ->required()
                        ->maxLength(255),
                    FileUpload::make('source')
                        ->label('Plantilla DOCX')
                        ->disk('private')
                        ->directory('documents/institutional-intake')
                        ->acceptedFileTypes([CreateInstitutionalFormatDraft::DOCX_MIME])
                        ->maxSize(10240)
                        ->required()
                        ->helperText('Usa placeholders como {{TITLE}}. No se aceptan macros, ActiveX ni relaciones externas.'),
                ])
                ->action(function (array $data): void {
                    $path = (string) ($data['source'] ?? '');
                    try {
                        if ($path === '' || ! Storage::disk('private')->exists($path)) {
                            throw new \RuntimeException('FORMAT_SOURCE_UPLOAD_MISSING');
                        }
                        $bytes = Storage::disk('private')->get($path);
                        $owner = User::query()->findOrFail((int) $data['owner_id']);
                        $version = app(CreateInstitutionalFormatDraft::class)->execute(
                            $owner,
                            (string) $data['name'],
                            basename($path),
                            $bytes,
                            auth()->user(),
                        );
                        Notification::make()
                            ->success()
                            ->title('Formato cargado')
                            ->body('La versión v' . $version->number . ' quedó lista para análisis.')
                            ->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()
                            ->danger()
                            ->title('No se pudo cargar el formato')
                            ->body($error->getMessage())
                            ->send();
                    } finally {
                        if ($path !== '' && Storage::disk('private')->exists($path)) {
                            Storage::disk('private')->delete($path);
                        }
                    }
                }),
        ];
    }
}
