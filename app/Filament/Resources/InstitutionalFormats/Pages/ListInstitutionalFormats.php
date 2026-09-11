<?php

namespace App\Filament\Resources\InstitutionalFormats\Pages;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\CreateInstitutionalFormatDraft;
use App\Filament\Resources\InstitutionalFormats\InstitutionalFormatResource;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Forms\Components\FileUpload;
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
                ->label('Subir formato de mi escuela')
                ->icon('heroicon-o-arrow-up-tray')
                ->schema([
                    TextInput::make('name')
                        ->label('Nombre para identificarlo')
                        ->placeholder('Ej. Formato Primaria 2026-2027')
                        ->required()
                        ->maxLength(255),
                    FileUpload::make('source')
                        ->label('Archivo Word (.docx)')
                        ->disk('private')
                        ->directory('documents/institutional-intake')
                        ->acceptedFileTypes([CreateInstitutionalFormatDraft::DOCX_MIME])
                        ->maxSize(10240)
                        ->required()
                        ->helperText('Sube el formato tal como te lo entrega tu institución. No necesitas editarlo ni agregar códigos o placeholders.'),
                ])
                ->action(function (array $data): void {
                    $path = (string) ($data['source'] ?? '');
                    try {
                        if ($path === '' || ! Storage::disk('private')->exists($path)) {
                            throw new \RuntimeException('FORMAT_SOURCE_UPLOAD_MISSING');
                        }
                        $actor = auth()->user();
                        if (! $actor instanceof User) {
                            throw new \RuntimeException('FORMAT_OWNER_REQUIRED');
                        }
                        $bytes = Storage::disk('private')->get($path);
                        $version = app(CreateInstitutionalFormatDraft::class)->execute(
                            $actor,
                            (string) $data['name'],
                            basename($path),
                            $bytes,
                            $actor,
                        );
                        $version = app(AnalyzeInstitutionalFormatVersion::class)->execute($version, $actor);
                        Notification::make()
                            ->success()
                            ->title('Formato analizado')
                            ->body('Detectamos la estructura del Word y preparamos una propuesta de campos. Revísala y genera una muestra.')
                            ->send();
                        $this->redirect(InstitutionalFormatResource::getUrl('view', ['record' => $version->format_id]));
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo cargar el formato')->body($error->getMessage())->send();
                    } finally {
                        if ($path !== '' && Storage::disk('private')->exists($path)) {
                            Storage::disk('private')->delete($path);
                        }
                    }
                }),
        ];
    }
}
