<?php

namespace App\Filament\Resources\InstitutionalFormats\Pages;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\CreateInstitutionalFormatDraft;
use App\Actions\Documents\RenderInstitutionalFormatSample;
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
                        ->helperText('Puedes subir una plantilla vacía o una planeación ya llena. No necesitas modificar el Word: en el siguiente paso podrás señalar visualmente qué zonas debe llenar el sistema.'),
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

                        $mapping = is_array($version->mapping) ? $version->mapping : [];
                        $hasMapping = (is_array($mapping['anchors'] ?? null) && $mapping['anchors'] !== [])
                            || (is_array($mapping['placeholders'] ?? null) && $mapping['placeholders'] !== []);
                        $previewReady = false;

                        if ($hasMapping) {
                            try {
                                app(RenderInstitutionalFormatSample::class)->execute($version, $actor);
                                $previewReady = true;
                            } catch (\Throwable $previewError) {
                                report($previewError);
                            }
                        }

                        Notification::make()
                            ->success()
                            ->title('Tu Word está listo para revisar')
                            ->body($previewReady
                                ? 'Marcamos nuestras sugerencias. Haz clic sobre cualquier zona para confirmar, cambiar o crear un campo.'
                                : 'Haz clic sobre las zonas que quieras llenar automáticamente y dinos qué información debe ir ahí.')
                            ->send();

                        $this->redirect(route('institutional-formats.designer', $version->format_id));
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
