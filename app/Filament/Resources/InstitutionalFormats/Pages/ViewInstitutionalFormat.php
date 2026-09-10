<?php

namespace App\Filament\Resources\InstitutionalFormats\Pages;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\ConfigureInstitutionalFormatMapping;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Enums\FormatSampleStatus;
use App\Filament\Resources\InstitutionalFormats\InstitutionalFormatResource;
use App\Models\FormatVersion;
use App\Models\FormatVersionSample;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Schema;

class ViewInstitutionalFormat extends ViewRecord
{
    protected static string $resource = InstitutionalFormatResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Formato institucional')->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('owner.name')->label('Docente'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('current_version')->label('Versión')->state(fn (): string => 'v' . $this->version()->number),
                TextEntry::make('source_name')->label('Archivo fuente')->state(fn (): ?string => $this->version()->sourceFile?->original_name),
                TextEntry::make('renderer')->label('Renderer')->state(fn (): string => (string) $this->version()->renderer),
                TextEntry::make('published_at')->label('Publicada')->state(fn () => $this->version()->published_at)->dateTime('d/m/Y H:i')->placeholder('No publicada'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Análisis y mapping')->schema([
                TextEntry::make('analysis_status')->label('Estado técnico')->state(fn (): string => (string) data_get($this->version()->validation_report, 'status', 'pendiente')),
                TextEntry::make('placeholders')->label('Placeholders detectados')->state(fn (): string => implode(', ', $this->placeholders()) ?: 'Pendientes de análisis'),
                TextEntry::make('mapping')->label('Mapping actual')->state(fn (): string => $this->mappingSummary()),
                TextEntry::make('warnings')->label('Advertencias')->state(fn (): string => implode(', ', array_map('strval', data_get($this->version()->validation_report, 'analysis.warnings', []))) ?: 'Sin advertencias'),
            ])->columns(1)->columnSpanFull(),
            Section::make('Muestra')->schema([
                TextEntry::make('sample_status')->label('Estado')->state(fn (): string => $this->latestSample()?->status?->value ?? 'Sin muestra'),
                TextEntry::make('sample_fingerprint')->label('Fingerprint')->state(fn (): ?string => $this->latestSample()?->fingerprint)->placeholder('—'),
                TextEntry::make('sample_review_note')->label('Nota de revisión')->state(fn (): ?string => $this->latestSample()?->review_note)->placeholder('—'),
            ])->columns(1)->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $version = $this->version();
        $sample = $this->latestSample();

        return [
            Action::make('analyze')
                ->label('Analizar DOCX')
                ->icon('heroicon-o-magnifying-glass')
                ->visible(fn (): bool => $version->published_at === null)
                ->action(fn () => $this->runAction(
                    fn () => app(AnalyzeInstitutionalFormatVersion::class)->execute($this->version(), auth()->user()),
                    'Formato analizado',
                )),
            Action::make('mapping')
                ->label('Configurar mapping')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => $version->published_at === null && $this->placeholders() !== [])
                ->schema($this->mappingFields())
                ->action(function (array $data): void {
                    $paths = [];
                    foreach ($this->placeholders() as $index => $token) {
                        $paths[$token] = (string) ($data['token_' . $index] ?? '');
                    }
                    $this->runAction(
                        fn () => app(ConfigureInstitutionalFormatMapping::class)->execute(
                            $this->version(),
                            ['schema_version' => 1, 'placeholders' => $paths],
                            auth()->user(),
                        ),
                        'Mapping guardado',
                    );
                }),
            Action::make('sample')
                ->label('Generar muestra')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => $version->published_at === null && $this->mappingReady())
                ->action(fn () => $this->runAction(
                    fn () => app(RenderInstitutionalFormatSample::class)->execute($this->version(), auth()->user()),
                    'Muestra generada',
                )),
            Action::make('downloadSampleDocx')
                ->label('Muestra DOCX')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $sample?->docx_file_id !== null)
                ->url(fn (): string => route('format-samples.download', [$this->latestSample(), $this->latestSample()?->docx_file_id]))
                ->openUrlInNewTab(),
            Action::make('downloadSamplePdf')
                ->label('Muestra PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $sample?->pdf_file_id !== null)
                ->url(fn (): string => route('format-samples.download', [$this->latestSample(), $this->latestSample()?->pdf_file_id]))
                ->openUrlInNewTab(),
            Action::make('approveSample')
                ->label('Aprobar muestra')
                ->color('success')
                ->schema([
                    Textarea::make('note')->label('Nota')->maxLength(2000)->rows(3),
                ])
                ->visible(fn (): bool => $sample?->status === FormatSampleStatus::Pending)
                ->action(fn (array $data) => $this->runAction(
                    fn () => app(ReviewInstitutionalFormatSample::class)->approve(
                        $this->latestSample(),
                        auth()->user(),
                        isset($data['note']) ? (string) $data['note'] : null,
                    ),
                    'Muestra aprobada',
                )),
            Action::make('rejectSample')
                ->label('Rechazar muestra')
                ->color('danger')
                ->schema([
                    Textarea::make('note')->label('Motivo')->required()->minLength(3)->maxLength(2000)->rows(3),
                ])
                ->visible(fn (): bool => $sample?->status === FormatSampleStatus::Pending)
                ->action(fn (array $data) => $this->runAction(
                    fn () => app(ReviewInstitutionalFormatSample::class)->reject(
                        $this->latestSample(),
                        auth()->user(),
                        (string) $data['note'],
                    ),
                    'Muestra rechazada',
                )),
            Action::make('publish')
                ->label('Publicar formato')
                ->color('success')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (): bool => $version->published_at === null && data_get($version->validation_report, 'status') === 'approved')
                ->action(fn () => $this->runAction(
                    fn () => app(PublishFormatVersion::class)->execute($this->version(), auth()->user()),
                    'Formato publicado',
                )),
        ];
    }

    /** @return array<int,TextInput> */
    private function mappingFields(): array
    {
        $current = is_array($this->version()->mapping) ? ($this->version()->mapping['placeholders'] ?? []) : [];
        $fields = [];
        foreach ($this->placeholders() as $index => $token) {
            $fields[] = TextInput::make('token_' . $index)
                ->label('{{' . $token . '}}')
                ->default(is_array($current) ? ($current[$token] ?? null) : null)
                ->placeholder('planning.title')
                ->required()
                ->maxLength(255);
        }

        return $fields;
    }

    private function version(): FormatVersion
    {
        return $this->getRecord()->versions()->with(['sourceFile', 'samples.docxFile', 'samples.pdfFile'])->orderByDesc('number')->firstOrFail();
    }

    /** @return list<string> */
    private function placeholders(): array
    {
        $values = data_get($this->version()->validation_report, 'analysis.placeholders', []);
        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    private function mappingReady(): bool
    {
        $mapping = $this->version()->mapping;
        return is_array($mapping) && is_array($mapping['placeholders'] ?? null) && $mapping['placeholders'] !== [];
    }

    private function latestSample(): ?FormatVersionSample
    {
        return $this->version()->samples()->with(['docxFile', 'pdfFile'])->orderByDesc('id')->first();
    }

    private function mappingSummary(): string
    {
        $mapping = $this->version()->mapping;
        $paths = is_array($mapping) ? ($mapping['placeholders'] ?? []) : [];
        if (! is_array($paths) || $paths === []) {
            return 'Sin configurar';
        }

        return collect($paths)->map(fn ($path, $token): string => '{{' . $token . '}} → ' . $path)->implode('; ');
    }

    private function runAction(callable $callback, string $success): void
    {
        try {
            $callback();
            $this->record = $this->getRecord()->fresh(['owner', 'versions']);
            Notification::make()->success()->title($success)->send();
        } catch (\Throwable $error) {
            report($error);
            Notification::make()->danger()->title('No se pudo completar la acción')->body($error->getMessage())->send();
        }
    }
}
