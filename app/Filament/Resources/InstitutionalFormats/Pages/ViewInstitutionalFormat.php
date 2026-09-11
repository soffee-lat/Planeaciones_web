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
            Section::make('Tu formato')
                ->description('El archivo original se conserva sin modificar. Nada se usará en tus planeaciones hasta que tú confirmes una muestra.')
                ->schema([
                    TextEntry::make('name')->label('Nombre'),
                    TextEntry::make('source_name')->label('Archivo original')->state(fn (): ?string => $this->version()->sourceFile?->original_name),
                    TextEntry::make('user_status')->label('Estado')->state(fn (): string => $this->humanStatus())->badge(),
                    TextEntry::make('published_at')->label('Activo desde')->state(fn () => $this->version()->published_at)->dateTime('d/m/Y H:i')->placeholder('Todavía no está activo'),
                ])->columns(2)->columnSpanFull(),

            Section::make('1. Esto fue lo que entendimos')
                ->description('El sistema buscó etiquetas y zonas de tu Word. Las relaciones automáticas son una propuesta: puedes corregirlas antes de usar el formato.')
                ->schema([
                    TextEntry::make('detected_count')->label('Campos encontrados')->state(fn (): string => (string) $this->candidateCount()),
                    TextEntry::make('mapped_count')->label('Relacionados automáticamente')->state(fn (): string => (string) $this->mappedCount()),
                    TextEntry::make('unmapped_count')->label('Por revisar')->state(fn (): string => (string) $this->unmappedCount()),
                    TextEntry::make('detected_fields')->label('Ejemplos de lo que entendimos')->state(fn (): string => $this->detectedExamples()),
                ])->columns(3)->columnSpanFull(),

            Section::make('2. Mira un ejemplo antes de decidir')
                ->description('La muestra usa datos ficticios para que veas en qué parte del documento colocaremos cada tipo de información.')
                ->schema([
                    TextEntry::make('preview_status')->label('Vista previa')->state(fn (): string => $this->previewStatus())->badge(),
                    TextEntry::make('next_step')->label('Qué hacer ahora')->state(fn (): string => $this->nextStep()),
                    TextEntry::make('sample_review_note')->label('Última observación')->state(fn (): ?string => $this->latestSample()?->review_note)->placeholder('Sin observaciones'),
                ])->columns(1)->columnSpanFull(),

            Section::make('3. Cuando el ejemplo se vea bien')
                ->description('Pulsa “Usar este formato”. Desde ese momento quedará disponible para tus planeaciones. Si algo está mal, corrige los campos y revisa una nueva muestra.')
                ->schema([
                    TextEntry::make('activation_status')->label('Uso en planeaciones')->state(fn (): string => $this->version()->published_at ? 'Activo y listo para usar' : 'Todavía no activo'),
                ])->columns(1)->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        $version = $this->version();
        $sample = $this->latestSample();

        return [
            Action::make('downloadSamplePdf')
                ->label('Ver ejemplo PDF')
                ->icon('heroicon-o-eye')
                ->color('primary')
                ->visible(fn (): bool => $sample?->pdf_file_id !== null)
                ->url(fn (): string => route('format-samples.download', [$this->latestSample(), $this->latestSample()?->pdf_file_id]))
                ->openUrlInNewTab(),

            Action::make('downloadSampleDocx')
                ->label('Ver ejemplo en Word')
                ->icon('heroicon-o-document-arrow-down')
                ->visible(fn (): bool => $sample?->docx_file_id !== null)
                ->url(fn (): string => route('format-samples.download', [$this->latestSample(), $this->latestSample()?->docx_file_id]))
                ->openUrlInNewTab(),

            Action::make('mapping')
                ->label('Corregir lo que entendimos')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $version->published_at === null && $this->hasDetectedCandidates())
                ->schema($this->mappingFields())
                ->action(function (array $data): void {
                    $anchors = [];
                    foreach ($this->anchors() as $index => $anchor) {
                        $value = trim((string) ($data['anchor_' . $index] ?? ''));
                        if ($value !== '') {
                            $anchors[(string) $anchor['id']] = $value;
                        }
                    }

                    $placeholders = [];
                    foreach ($this->placeholders() as $index => $token) {
                        $value = trim((string) ($data['token_' . $index] ?? ''));
                        if ($value !== '') {
                            $placeholders[$token] = $value;
                        }
                    }

                    $this->runAction(function () use ($anchors, $placeholders): void {
                        $configured = app(ConfigureInstitutionalFormatMapping::class)->execute(
                            $this->version(),
                            ['schema_version' => 2, 'anchors' => $anchors, 'placeholders' => $placeholders],
                            auth()->user(),
                        );
                        app(RenderInstitutionalFormatSample::class)->execute($configured, auth()->user());
                    }, 'Guardamos tus correcciones y preparamos un nuevo ejemplo');
                }),

            Action::make('sample')
                ->label('Crear ejemplo')
                ->icon('heroicon-o-document-duplicate')
                ->visible(fn (): bool => $version->published_at === null && $this->mappingReady() && $sample === null)
                ->action(fn () => $this->runAction(
                    fn () => app(RenderInstitutionalFormatSample::class)->execute($this->version(), auth()->user()),
                    'Ejemplo generado',
                )),

            Action::make('useFormat')
                ->label('Usar este formato')
                ->color('success')
                ->icon('heroicon-o-check-circle')
                ->requiresConfirmation()
                ->modalHeading('¿El ejemplo se ve como esperabas?')
                ->modalDescription('Al confirmar, este formato quedará activo para tus planeaciones. Si algo está mal, cancela y usa “Corregir lo que entendimos”.')
                ->visible(fn (): bool => $version->published_at === null && $sample?->status === FormatSampleStatus::Pending)
                ->action(fn () => $this->runAction(function (): void {
                    app(ReviewInstitutionalFormatSample::class)->approve(
                        $this->latestSample(),
                        auth()->user(),
                        'Muestra aceptada por el propietario.',
                    );
                    app(PublishFormatVersion::class)->execute($this->version()->fresh(), auth()->user());
                }, 'Formato activado y listo para usar')),

            Action::make('publish')
                ->label('Activar formato')
                ->color('success')
                ->icon('heroicon-o-check-badge')
                ->requiresConfirmation()
                ->visible(fn (): bool => $version->published_at === null && data_get($version->validation_report, 'status') === 'approved')
                ->action(fn () => $this->runAction(
                    fn () => app(PublishFormatVersion::class)->execute($this->version(), auth()->user()),
                    'Formato activado y listo para usar',
                )),

            Action::make('rejectSample')
                ->label('El ejemplo no quedó bien')
                ->color('danger')
                ->schema([
                    Textarea::make('note')
                        ->label('¿Qué viste mal?')
                        ->helperText('Esto es solo una nota para ayudarte a recordar qué debes corregir en “Corregir lo que entendimos”.')
                        ->required()
                        ->minLength(3)
                        ->maxLength(2000)
                        ->rows(3),
                ])
                ->visible(fn (): bool => $version->published_at === null && $sample?->status === FormatSampleStatus::Pending)
                ->action(fn (array $data) => $this->runAction(
                    fn () => app(ReviewInstitutionalFormatSample::class)->reject(
                        $this->latestSample(),
                        auth()->user(),
                        (string) $data['note'],
                    ),
                    'Anotamos que el ejemplo necesita correcciones',
                )),

            Action::make('analyze')
                ->label('Analizar otra vez')
                ->icon('heroicon-o-arrow-path')
                ->visible(fn (): bool => $version->published_at === null)
                ->action(fn () => $this->runAction(function (): void {
                    $analyzed = app(AnalyzeInstitutionalFormatVersion::class)->execute($this->version(), auth()->user());
                    if ($this->mappingAvailable($analyzed)) {
                        app(RenderInstitutionalFormatSample::class)->execute($analyzed, auth()->user());
                    }
                }, 'Volvimos a analizar el formato')),
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
            $sourceLabel = trim((string) ($anchor['label'] ?? $id));
            $confidence = isset($anchor['confidence']) ? (int) $anchor['confidence'] : null;
            $suggested = $anchor['suggested_path'] ?? null;

            $fields[] = Select::make('anchor_' . $index)
                ->label('En tu Word aparece: “' . $sourceLabel . '”')
                ->options($options)
                ->searchable()
                ->native(false)
                ->placeholder('No llenar esta zona automáticamente')
                ->default($currentAnchors[$id] ?? $suggested ?? null)
                ->helperText($suggested
                    ? 'Nuestra sugerencia: “' . app(InstitutionalFormatFieldCatalog::class)->labelFor((string) $suggested) . '”' . ($confidence ? ' (' . $confidence . '% de confianza).' : '.')
                    : 'No estamos seguros de qué dato corresponde aquí. Elige uno solo si esta zona debe llenarse automáticamente.');
        }

        foreach ($this->placeholders() as $index => $token) {
            $fields[] = Select::make('token_' . $index)
                ->label('En tu Word aparece: {{' . $token . '}}')
                ->options($options)
                ->searchable()
                ->native(false)
                ->placeholder('No usar este campo')
                ->default($currentTokens[$token] ?? null)
                ->helperText('Este marcador ya venía dentro del archivo. Puedes indicar qué información debe reemplazarlo.');
        }

        return $fields;
    }

    private function version(): FormatVersion
    {
        return $this->getRecord()->versions()
            ->with(['sourceFile', 'samples.docxFile', 'samples.pdfFile'])
            ->orderByDesc('number')
            ->firstOrFail();
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

    private function hasDetectedCandidates(): bool
    {
        return $this->anchors() !== [] || $this->placeholders() !== [];
    }

    private function mappingReady(): bool
    {
        return $this->mappingAvailable($this->version());
    }

    private function mappingAvailable(FormatVersion $version): bool
    {
        $mapping = $version->mapping;
        return is_array($mapping)
            && ((is_array($mapping['anchors'] ?? null) && $mapping['anchors'] !== [])
                || (is_array($mapping['placeholders'] ?? null) && $mapping['placeholders'] !== []));
    }

    private function latestSample(): ?FormatVersionSample
    {
        return $this->version()->samples()->with(['docxFile', 'pdfFile'])->orderByDesc('id')->first();
    }

    private function candidateCount(): int
    {
        return count($this->anchors()) + count($this->placeholders());
    }

    private function mappedCount(): int
    {
        $mapping = $this->version()->mapping;
        if (! is_array($mapping)) {
            return 0;
        }

        return count(is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [])
            + count(is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : []);
    }

    private function unmappedCount(): int
    {
        return max(0, $this->candidateCount() - $this->mappedCount());
    }

    private function detectedExamples(): string
    {
        if ($this->candidateCount() === 0) {
            return 'No encontramos etiquetas claras para relacionar automáticamente.';
        }

        $catalog = app(InstitutionalFormatFieldCatalog::class);
        $mapping = is_array($this->version()->mapping) ? $this->version()->mapping : [];
        $anchorMapping = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $tokenMapping = is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : [];
        $rows = [];

        foreach ($this->anchors() as $anchor) {
            $id = (string) ($anchor['id'] ?? '');
            $label = trim((string) ($anchor['label'] ?? $id));
            $path = $anchorMapping[$id] ?? null;
            $rows[] = '“' . $label . '” → ' . ($path ? $catalog->labelFor((string) $path) : 'sin relacionar');
        }

        foreach ($this->placeholders() as $token) {
            $path = $tokenMapping[$token] ?? null;
            $rows[] = '{{' . $token . '}} → ' . ($path ? $catalog->labelFor((string) $path) : 'sin relacionar');
        }

        $total = count($rows);
        $shown = array_slice($rows, 0, 8);
        $summary = implode(' | ', $shown);
        if ($total > count($shown)) {
            $summary .= ' | y ' . ($total - count($shown)) . ' campo(s) más';
        }

        return $summary;
    }

    private function previewStatus(): string
    {
        $sample = $this->latestSample();
        if (! $sample) {
            return $this->mappingReady() ? 'Aún no generada' : 'Necesita revisar campos primero';
        }

        return match ($sample->status) {
            FormatSampleStatus::Pending => 'Lista para revisar',
            FormatSampleStatus::Approved => 'Aceptada',
            FormatSampleStatus::Rejected => 'Marcada para corregir',
        };
    }

    private function nextStep(): string
    {
        if ($this->version()->published_at !== null) {
            return 'No necesitas hacer nada más. Este formato ya está activo.';
        }

        $sample = $this->latestSample();
        if (! $sample) {
            return $this->mappingReady()
                ? 'Pulsa “Crear ejemplo” para ver cómo quedará el documento antes de activarlo.'
                : 'Pulsa “Corregir lo que entendimos” y relaciona al menos un campo que deba llenarse automáticamente.';
        }

        return match ($sample->status) {
            FormatSampleStatus::Pending => 'Primero abre “Ver ejemplo PDF”. Si se ve bien, pulsa “Usar este formato”. Si algo quedó en la zona equivocada, pulsa “Corregir lo que entendimos”.',
            FormatSampleStatus::Approved => 'La muestra ya fue aceptada. Solo falta activar el formato.',
            FormatSampleStatus::Rejected => 'Pulsa “Corregir lo que entendimos”, ajusta las relaciones y te prepararemos un nuevo ejemplo automáticamente.',
        };
    }

    private function humanStatus(): string
    {
        if ($this->version()->published_at !== null) {
            return 'Activo';
        }
        if ($this->latestSample()?->status === FormatSampleStatus::Pending) {
            return 'Esperando tu revisión';
        }
        if ($this->latestSample()?->status === FormatSampleStatus::Rejected) {
            return 'Necesita correcciones';
        }
        if ($this->mappingReady()) {
            return 'Listo para crear ejemplo';
        }

        return 'Analizado';
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
