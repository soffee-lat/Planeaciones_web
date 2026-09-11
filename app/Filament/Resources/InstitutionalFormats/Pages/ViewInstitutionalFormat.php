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
use App\Services\Documents\InstitutionalFormatFieldCatalog;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
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
            Section::make('Mi formato')->schema([
                TextEntry::make('name')->label('Nombre'),
                TextEntry::make('status')->label('Estado')->badge(),
                TextEntry::make('current_version')->label('Versión')->state(fn (): string => 'v' . $this->version()->number),
                TextEntry::make('source_name')->label('Archivo original')->state(fn (): ?string => $this->version()->sourceFile?->original_name),
                TextEntry::make('published_at')->label('Activo desde')->state(fn () => $this->version()->published_at)->dateTime('d/m/Y H:i')->placeholder('Aún no activo'),
            ])->columns(2)->columnSpanFull(),
            Section::make('Campos detectados')
                ->description('El sistema intenta reconocer automáticamente las zonas del formato. Puedes corregir cualquier correspondencia antes de generar la muestra.')
                ->schema([
                    TextEntry::make('analysis_status')->label('Estado técnico')->state(fn (): string => (string) data_get($this->version()->validation_report, 'status', 'pendiente')),
                    TextEntry::make('detected_fields')->label('Detección automática')->state(fn (): string => $this->detectedSummary()),
                    TextEntry::make('mapping')->label('Campos que se llenarán')->state(fn (): string => $this->mappingSummary()),
                    TextEntry::make('warnings')->label('Advertencias')->state(fn (): string => implode(', ', array_map('strval', data_get($this->version()->validation_report, 'analysis.warnings', []))) ?: 'Sin advertencias'),
                ])->columns(1)->columnSpanFull(),
            Section::make('Vista previa')->schema([
                TextEntry::make('sample_status')->label('Estado')->state(fn (): string => $this->latestSample()?->status?->value ?? 'Sin muestra'),
                TextEntry::make('sample_review_note')->label('Nota')->state(fn (): ?string => $this->latestSample()?->review_note)->placeholder('—'),
            ])->columns(1)->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $version = $this->version();
        $sample = $this->latestSample();
        return [
            Action::make('analyze')
                ->label('Volver a analizar')
                ->icon('heroicon-o-magnifying-glass')
                ->visible(fn (): bool => $version->published_at === null)
                ->action(fn () => $this->runAction(fn () => app(AnalyzeInstitutionalFormatVersion::class)->execute($this->version(), auth()->user()), 'Formato analizado')),
            Action::make('mapping')
                ->label('Revisar campos')
                ->icon('heroicon-o-adjustments-horizontal')
                ->visible(fn (): bool => $version->published_at === null && $this->hasDetectedCandidates())
                ->schema($this->mappingFields())
                ->action(function (array $data): void {
                    $anchors = [];
                    foreach ($this->anchors() as $index => $anchor) {
                        $value = trim((string) ($data['anchor_' . $index] ?? ''));
                        if ($value !== '') $anchors[(string) $anchor['id']] = $value;
                    }
                    $placeholders = [];
                    foreach ($this->placeholders() as $index => $token) {
                        $value = trim((string) ($data['token_' . $index] ?? ''));
                        if ($value !== '') $placeholders[$token] = $value;
                    }
                    $this->runAction(
                        fn () => app(ConfigureInstitutionalFormatMapping::class)->execute(
                            $this->version(),
                            ['schema_version' => 2, 'anchors' => $anchors, 'placeholders' => $placeholders],
                            auth()->user(),
                        ),
                        'Campos guardados',
                    );
                }),
            Action::make('sample')
                ->label('Generar muestra')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => $version->published_at === null && $this->mappingReady())
                ->action(fn () => $this->runAction(fn () => app(RenderInstitutionalFormatSample::class)->execute($this->version(), auth()->user()), 'Muestra generada')),
            Action::make('downloadSampleDocx')
                ->label('Abrir muestra DOCX')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $sample?->docx_file_id !== null)
                ->url(fn (): string => route('format-samples.download', [$this->latestSample(), $this->latestSample()?->docx_file_id]))
                ->openUrlInNewTab(),
            Action::make('downloadSamplePdf')
                ->label('Abrir muestra PDF')
                ->icon('heroicon-o-arrow-down-tray')
                ->visible(fn (): bool => $sample?->pdf_file_id !== null)
                ->url(fn (): string => route('format-samples.download', [$this->latestSample(), $this->latestSample()?->pdf_file_id]))
                ->openUrlInNewTab(),
            Action::make('approveSample')
                ->label('La muestra está bien')
                ->color('success')
                ->visible(fn (): bool => $sample?->status === FormatSampleStatus::Pending)
                ->action(fn () => $this->runAction(fn () => app(ReviewInstitutionalFormatSample::class)->approve($this->latestSample(), auth()->user(), 'Muestra aceptada por el propietario.'), 'Muestra aceptada')),
            Action::make('rejectSample')
                ->label('Necesito corregir campos')
                ->color('danger')
                ->schema([Textarea::make('note')->label('¿Qué quedó mal?')->required()->minLength(3)->maxLength(2000)->rows(3)])
                ->visible(fn (): bool => $sample?->status === FormatSampleStatus::Pending)
                ->action(fn (array $data) => $this->runAction(fn () => app(ReviewInstitutionalFormatSample::class)->reject($this->latestSample(), auth()->user(), (string) $data['note']), 'Muestra marcada para corrección')),
            Action::make('publish')
                ->label('Activar formato')
                ->color('success')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (): bool => $version->published_at === null && data_get($version->validation_report, 'status') === 'approved')
                ->action(fn () => $this->runAction(fn () => app(PublishFormatVersion::class)->execute($this->version(), auth()->user()), 'Formato listo para usar')),
        ];
    }

    /** @return array<int,Select> */
    private function mappingFields(): array
    {
        $options = app(InstitutionalFormatFieldCatalog::class)->options();
        $mapping = is_array($this->version()->mapping) ? $this->version()->mapping : [];
        $currentAnchors = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $currentTokens = is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : [];
        $fields = [];
        foreach ($this->anchors() as $index => $anchor) {
            $id = (string) $anchor['id'];
            $confidence = $anchor['confidence'] ?? null;
            $label = (string) ($anchor['label'] ?? $id) . ($confidence ? ' · ' . $confidence . '% confianza' : '');
            $fields[] = Select::make('anchor_' . $index)->label($label)->options($options)->searchable()->native(false)->placeholder('No llenar este campo')->default($currentAnchors[$id] ?? $anchor['suggested_path'] ?? null);
        }
        foreach ($this->placeholders() as $index => $token) {
            $fields[] = Select::make('token_' . $index)->label('Campo técnico existente: {{' . $token . '}}')->options($options)->searchable()->native(false)->placeholder('No usar')->default($currentTokens[$token] ?? null);
        }
        return $fields;
    }

    private function version(): FormatVersion
    {
        return $this->getRecord()->versions()->with(['sourceFile', 'samples.docxFile', 'samples.pdfFile'])->orderByDesc('number')->firstOrFail();
    }

    /** @return list<array<string,mixed>> */
    private function anchors(): array
    {
        $values = data_get($this->version()->validation_report, 'analysis.anchors', []);
        return is_array($values) ? array_values(array_filter($values, 'is_array')) : [];
    }

    /** @return list<string> */
    private function placeholders(): array
    {
        $values = data_get($this->version()->validation_report, 'analysis.placeholders', []);
        return is_array($values) ? array_values(array_map('strval', $values)) : [];
    }

    private function hasDetectedCandidates(): bool { return $this->anchors() !== [] || $this->placeholders() !== []; }

    private function mappingReady(): bool
    {
        $mapping = $this->version()->mapping;
        return is_array($mapping) && ((is_array($mapping['anchors'] ?? null) && $mapping['anchors'] !== []) || (is_array($mapping['placeholders'] ?? null) && $mapping['placeholders'] !== []));
    }

    private function latestSample(): ?FormatVersionSample
    {
        return $this->version()->samples()->with(['docxFile', 'pdfFile'])->orderByDesc('id')->first();
    }

    private function detectedSummary(): string
    {
        if ($this->anchors() === [] && $this->placeholders() === []) return 'No se identificaron campos con suficiente claridad. Puedes volver a analizar otro archivo.';
        $catalog = app(InstitutionalFormatFieldCatalog::class);
        $rows = [];
        foreach ($this->anchors() as $anchor) {
            $path = $anchor['suggested_path'] ?? null;
            $rows[] = (string) ($anchor['label'] ?? $anchor['id']) . ($path ? ' → ' . $catalog->labelFor((string) $path) : ' → revisar');
        }
        foreach ($this->placeholders() as $token) $rows[] = '{{' . $token . '}} → campo técnico detectado';
        return implode('; ', array_slice($rows, 0, 20));
    }

    private function mappingSummary(): string
    {
        $mapping = $this->version()->mapping;
        if (! is_array($mapping)) return 'Sin configurar';
        $count = count(is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : []) + count(is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : []);
        return $count > 0 ? $count . ' campo(s) configurado(s)' : 'Sin configurar';
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
