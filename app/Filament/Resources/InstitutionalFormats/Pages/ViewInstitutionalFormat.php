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
use App\Services\Documents\GenericInstitutionalFieldResolver;
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
    private const CUSTOM_AI = '__CUSTOM_AI__';
    private const MANUAL = '__MANUAL__';

    protected static string $resource = InstitutionalFormatResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Tu formato')
                ->description('El archivo original se conserva sin modificar. Planeaciones se adapta a tu Word; no necesitas cambiar la plantilla que te exige tu escuela.')
                ->schema([
                    TextEntry::make('name')->label('Nombre'),
                    TextEntry::make('source_name')->label('Archivo original')->state(fn (): ?string => $this->version()->sourceFile?->original_name),
                    TextEntry::make('user_status')->label('Estado')->state(fn (): string => $this->humanStatus())->badge(),
                    TextEntry::make('published_at')->label('Activo desde')->state(fn () => $this->version()->published_at)->dateTime('d/m/Y H:i')->placeholder('Todavía no está activo'),
                    TextEntry::make('source_content_type')
                        ->label('Qué detectamos en el Word')
                        ->state(fn (): string => $this->sourceContentDescription())
                        ->columnSpanFull(),
                ])->columns(2)->columnSpanFull(),

            Section::make('1. Define qué significa cada zona de tu formato')
                ->description('El sistema propone relaciones, pero tu documento manda. Puedes relacionar una zona con un dato conocido, convertirla en un campo propio que redactará la IA o dejarla manual. No asumimos que tu formato se parece a ningún otro.')
                ->schema([
                    TextEntry::make('detected_count')->label('Campos encontrados')->state(fn (): string => (string) $this->candidateCount()),
                    TextEntry::make('mapped_count')->label('Definidos')->state(fn (): string => (string) $this->mappedCount()),
                    TextEntry::make('unmapped_count')->label('Por revisar')->state(fn (): string => (string) $this->unmappedCount()),
                    TextEntry::make('detected_fields')->label('Ejemplos de lo que entendimos')->state(fn (): string => $this->detectedExamples())->columnSpanFull(),
                    TextEntry::make('previous_content_examples')
                        ->label('Contenido anterior que se usará solo como ejemplo')
                        ->state(fn (): string => $this->priorContentExamples())
                        ->visible(fn (): bool => $this->filledSource())
                        ->columnSpanFull(),
                ])->columns(3)->columnSpanFull(),

            Section::make('2. Mira un ejemplo antes de decidir')
                ->description($this->filledSource()
                    ? 'La muestra sustituye la información anterior por datos ficticios nuevos. Revisa que el texto viejo ya no aparezca y que cada dato nuevo quede exactamente en la zona que corresponde.'
                    : 'La muestra usa datos ficticios para que veas qué zonas se llenarán sin alterar el diseño original del Word.')
                ->schema([
                    TextEntry::make('preview_status')->label('Vista previa')->state(fn (): string => $this->previewStatus())->badge(),
                    TextEntry::make('next_step')->label('Qué hacer ahora')->state(fn (): string => $this->nextStep()),
                    TextEntry::make('sample_review_note')->label('Última observación')->state(fn (): ?string => $this->latestSample()?->review_note)->placeholder('Sin observaciones'),
                ])->columns(1)->columnSpanFull(),

            Section::make('3. Cuando el ejemplo se vea bien')
                ->description('Pulsa “Usar este formato”. Desde ese momento quedará disponible para tus planeaciones exactamente con la estructura que confirmaste.')
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
                ->label('Definir mi formato')
                ->icon('heroicon-o-pencil-square')
                ->visible(fn (): bool => $version->published_at === null && $this->hasDetectedCandidates())
                ->schema($this->mappingFields())
                ->action(function (array $data): void {
                    $version = $this->version();
                    $existing = is_array($version->mapping) ? $version->mapping : [];
                    $customFields = is_array($existing['custom_fields'] ?? null) ? $existing['custom_fields'] : [];
                    $ignored = array_fill_keys(
                        array_map('strval', is_array($existing['ignored_zones'] ?? null) ? $existing['ignored_zones'] : []),
                        true,
                    );
                    $resolver = app(GenericInstitutionalFieldResolver::class);
                    $anchors = [];

                    foreach ($this->anchors() as $index => $anchor) {
                        $id = (string) ($anchor['id'] ?? '');
                        $targetId = (string) ($anchor['target_id'] ?? $id);
                        $label = trim((string) ($anchor['label'] ?? $id));
                        $value = trim((string) ($data['anchor_' . $index] ?? ''));

                        if ($value === self::CUSTOM_AI) {
                            $key = $resolver->customKey($label, $id);
                            $instruction = trim((string) ($data['anchor_instruction_' . $index] ?? ''));
                            $type = trim((string) ($data['anchor_type_' . $index] ?? 'long_text'));
                            $customFields[$key] = [
                                'label' => $label,
                                'type' => in_array($type, ['text', 'long_text', 'date', 'list', 'table', 'repeating_block'], true) ? $type : 'long_text',
                                'instruction' => $instruction !== '' ? $instruction : $resolver->defaultInstruction($label),
                            ];
                            $anchors[$id] = 'custom.' . $key;
                            unset($ignored[$id], $ignored[$targetId]);
                            continue;
                        }

                        if ($value === self::MANUAL || $value === '') {
                            if ($id !== '') {
                                $ignored[$id] = true;
                            }
                            if ($targetId !== '') {
                                $ignored[$targetId] = true;
                            }
                            continue;
                        }

                        $anchors[$id] = $value;
                        unset($ignored[$id], $ignored[$targetId]);
                    }

                    $placeholders = [];
                    foreach ($this->placeholders() as $index => $token) {
                        $value = trim((string) ($data['token_' . $index] ?? ''));
                        if ($value === self::CUSTOM_AI) {
                            $label = str_replace(['_', '-', '.'], ' ', $token);
                            $key = $resolver->customKey($label, 'token:' . $token);
                            $instruction = trim((string) ($data['token_instruction_' . $index] ?? ''));
                            $type = trim((string) ($data['token_type_' . $index] ?? 'long_text'));
                            $customFields[$key] = [
                                'label' => $label,
                                'type' => in_array($type, ['text', 'long_text', 'date', 'list', 'table', 'repeating_block'], true) ? $type : 'long_text',
                                'instruction' => $instruction !== '' ? $instruction : $resolver->defaultInstruction($label),
                            ];
                            $placeholders[$token] = 'custom.' . $key;
                            continue;
                        }
                        if ($value === self::MANUAL || $value === '') {
                            continue;
                        }
                        $placeholders[$token] = $value;
                    }

                    $this->runAction(function () use ($anchors, $placeholders, $customFields, $ignored): void {
                        $configured = app(ConfigureInstitutionalFormatMapping::class)->execute(
                            $this->version(),
                            [
                                'schema_version' => 2,
                                'anchors' => $anchors,
                                'placeholders' => $placeholders,
                                'custom_fields' => $customFields,
                                'ignored_zones' => array_keys($ignored),
                            ],
                            auth()->user(),
                            preserveVisualBindings: false,
                        );
                        app(RenderInstitutionalFormatSample::class)->execute($configured, auth()->user());
                    }, 'Guardamos la definición de tu formato y preparamos un nuevo ejemplo');
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
                ->modalDescription($this->filledSource()
                    ? 'Confirma solo si el contenido anterior ya fue reemplazado donde corresponde y el diseño original se conserva.'
                    : 'Al confirmar, este formato quedará activo para tus planeaciones. Si algo está mal, cancela y usa “Definir mi formato”.')
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
                        ->helperText('Anota qué zona debe corregirse. Después usa “Definir mi formato” para cambiar su significado o dejarla manual.')
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

    /** @return array<int,mixed> */
    private function mappingFields(): array
    {
        $catalog = app(InstitutionalFormatFieldCatalog::class);
        $options = [
            self::CUSTOM_AI => 'Campo propio de mi formato — generar con IA',
            self::MANUAL => 'Mantener manual / no llenar automáticamente',
            ...$catalog->options(),
        ];
        $mapping = is_array($this->version()->mapping) ? $this->version()->mapping : [];
        $currentAnchors = is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [];
        $currentTokens = is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : [];
        $custom = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $ignored = array_fill_keys(array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : []), true);
        $fields = [];
        $resolver = app(GenericInstitutionalFieldResolver::class);

        foreach ($this->anchors() as $index => $anchor) {
            $id = (string) $anchor['id'];
            $targetId = (string) ($anchor['target_id'] ?? $id);
            $sourceLabel = trim((string) ($anchor['label'] ?? $id));
            $confidence = isset($anchor['confidence']) ? (int) $anchor['confidence'] : null;
            $suggested = $anchor['suggested_path'] ?? null;
            $previous = trim((string) ($anchor['current_value_excerpt'] ?? ''));
            $currentPath = (string) ($currentAnchors[$id] ?? '');
            $isCustom = str_starts_with($currentPath, 'custom.');
            $customKey = $isCustom ? substr($currentPath, strlen('custom.')) : $resolver->customKey($sourceLabel, $id);
            $definition = is_array($custom[$customKey] ?? null) ? $custom[$customKey] : [];

            $helper = $suggested
                ? 'Nuestra sugerencia: “' . $catalog->labelFor((string) $suggested) . '”' . ($confidence ? ' (' . $confidence . '% de confianza).' : '.')
                : 'Si este apartado es propio de tu escuela, elige “Campo propio de mi formato”. La IA lo recibirá como requisito sin que tengamos que programar ese formato.';

            if ($previous !== '') {
                $helper .= ' En el ejemplo aparece: “' . $previous . '”. Se usará solo para entender intención y estilo, no para copiarlo.';
            }

            $default = $isCustom
                ? self::CUSTOM_AI
                : ((isset($ignored[$id]) || isset($ignored[$targetId])) ? self::MANUAL : ($currentPath !== '' ? $currentPath : ($suggested ?? null)));

            $fields[] = Select::make('anchor_' . $index)
                ->label('En tu Word aparece: “' . $sourceLabel . '”')
                ->options($options)
                ->searchable()
                ->native(false)
                ->placeholder('Elige qué significa esta zona')
                ->default($default)
                ->helperText($helper);

            $fields[] = Select::make('anchor_type_' . $index)
                ->label('Tipo de contenido para “' . $sourceLabel . '”')
                ->options([
                    'text' => 'Texto corto',
                    'long_text' => 'Texto largo',
                    'list' => 'Lista',
                    'date' => 'Fecha',
                    'table' => 'Tabla / matriz',
                    'repeating_block' => 'Bloque repetible',
                ])
                ->native(false)
                ->default((string) ($definition['type'] ?? 'long_text'))
                ->helperText('Solo se usa cuando eliges “Campo propio de mi formato”.');

            $fields[] = Textarea::make('anchor_instruction_' . $index)
                ->label('Qué debe contener “' . $sourceLabel . '”')
                ->default((string) ($definition['instruction'] ?? ''))
                ->placeholder($resolver->defaultInstruction($sourceLabel))
                ->rows(2)
                ->maxLength(1000)
                ->helperText('Opcional. Describe qué espera tu escuela en este apartado. Si subiste una planeación llena, el ejemplo anterior también ayudará a interpretar la intención.');
        }

        foreach ($this->placeholders() as $index => $token) {
            $currentPath = (string) ($currentTokens[$token] ?? '');
            $isCustom = str_starts_with($currentPath, 'custom.');
            $label = str_replace(['_', '-', '.'], ' ', $token);
            $customKey = $isCustom ? substr($currentPath, strlen('custom.')) : $resolver->customKey($label, 'token:' . $token);
            $definition = is_array($custom[$customKey] ?? null) ? $custom[$customKey] : [];

            $fields[] = Select::make('token_' . $index)
                ->label('En tu Word aparece: {{' . $token . '}}')
                ->options($options)
                ->searchable()
                ->native(false)
                ->placeholder('Elige qué significa este marcador')
                ->default($isCustom ? self::CUSTOM_AI : ($currentPath !== '' ? $currentPath : null))
                ->helperText('El marcador permanece en el archivo original; solo definimos qué información debe sustituirlo.');

            $fields[] = Select::make('token_type_' . $index)
                ->label('Tipo de contenido para {{' . $token . '}}')
                ->options([
                    'text' => 'Texto corto',
                    'long_text' => 'Texto largo',
                    'list' => 'Lista',
                    'date' => 'Fecha',
                    'table' => 'Tabla / matriz',
                    'repeating_block' => 'Bloque repetible',
                ])
                ->native(false)
                ->default((string) ($definition['type'] ?? 'long_text'));

            $fields[] = Textarea::make('token_instruction_' . $index)
                ->label('Qué debe contener {{' . $token . '}}')
                ->default((string) ($definition['instruction'] ?? ''))
                ->rows(2)
                ->maxLength(1000);
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
                || (is_array($mapping['placeholders'] ?? null) && $mapping['placeholders'] !== [])
                || (is_array($mapping['fragments'] ?? null) && $mapping['fragments'] !== []));
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

        $mapped = count(is_array($mapping['anchors'] ?? null) ? $mapping['anchors'] : [])
            + count(is_array($mapping['placeholders'] ?? null) ? $mapping['placeholders'] : []);

        $ignored = array_fill_keys(array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : []), true);
        foreach ($this->anchors() as $anchor) {
            $id = (string) ($anchor['id'] ?? '');
            $targetId = (string) ($anchor['target_id'] ?? $id);
            if (! isset(($mapping['anchors'] ?? [])[$id]) && (isset($ignored[$id]) || isset($ignored[$targetId]))) {
                $mapped++;
            }
        }

        return min($this->candidateCount(), $mapped);
    }

    private function unmappedCount(): int
    {
        return max(0, $this->candidateCount() - $this->mappedCount());
    }

    private function filledSource(): bool
    {
        return data_get($this->version()->validation_report, 'analysis.source_content_mode') === 'filled_example';
    }

    private function sourceContentDescription(): string
    {
        if ($this->filledSource()) {
            $count = (int) data_get($this->version()->validation_report, 'analysis.existing_value_count', 0);
            return 'Parece una planeación ya llena. Detectamos ' . $count . ' zona(s) con información anterior. La usamos únicamente como ejemplo semántico para entender qué espera tu formato; el archivo original no se modifica.';
        }

        return 'Parece una plantilla o formato sin contenido previo relevante. Puedes definir qué significa cada zona sin modificar el Word original.';
    }

    private function priorContentExamples(): string
    {
        $rows = [];
        foreach ($this->anchors() as $anchor) {
            $previous = trim((string) ($anchor['current_value_excerpt'] ?? ''));
            if ($previous === '') {
                continue;
            }
            $label = trim((string) ($anchor['label'] ?? 'Campo'));
            $rows[] = '“' . $label . '” actualmente contiene “' . $previous . '”';
            if (count($rows) >= 5) {
                break;
            }
        }

        return $rows === []
            ? 'Detectamos contenido anterior, pero no necesitamos mostrarlo completo para configurar el formato.'
            : implode(' | ', $rows) . '. Se usará como referencia semántica, no como texto para copiar.';
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
        $custom = is_array($mapping['custom_fields'] ?? null) ? $mapping['custom_fields'] : [];
        $ignored = array_fill_keys(array_map('strval', is_array($mapping['ignored_zones'] ?? null) ? $mapping['ignored_zones'] : []), true);
        $rows = [];

        foreach ($this->anchors() as $anchor) {
            $id = (string) ($anchor['id'] ?? '');
            $targetId = (string) ($anchor['target_id'] ?? $id);
            $label = trim((string) ($anchor['label'] ?? $id));
            $path = $anchorMapping[$id] ?? null;
            if ($path) {
                $destination = str_starts_with((string) $path, 'custom.')
                    ? 'campo propio: ' . (string) ($custom[substr((string) $path, strlen('custom.'))]['label'] ?? $label)
                    : $catalog->labelFor((string) $path);
            } elseif (isset($ignored[$id]) || isset($ignored[$targetId])) {
                $destination = 'manual / no automático';
            } else {
                $destination = 'sin definir';
            }
            $rows[] = '“' . $label . '” → ' . $destination;
        }

        foreach ($this->placeholders() as $token) {
            $path = $tokenMapping[$token] ?? null;
            $destination = $path
                ? (str_starts_with((string) $path, 'custom.')
                    ? 'campo propio'
                    : $catalog->labelFor((string) $path))
                : 'sin definir';
            $rows[] = '{{' . $token . '}} → ' . $destination;
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
            return $this->mappingReady() ? 'Aún no generada' : 'Necesita definir campos primero';
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
                ? 'Pulsa “Crear ejemplo” para comprobar que el Word conserva su diseño y cada dato aparece en la zona correcta.'
                : 'Pulsa “Definir mi formato” y define al menos una zona que deba llenarse automáticamente.';
        }

        return match ($sample->status) {
            FormatSampleStatus::Pending => $this->filledSource()
                ? 'Abre la muestra y confirma que la información anterior fue sustituida donde corresponde, sin alterar el formato. Si es así, pulsa “Usar este formato”.'
                : 'Abre la muestra. Si se ve igual que tu formato y los datos están en las zonas correctas, pulsa “Usar este formato”.',
            FormatSampleStatus::Approved => 'La muestra ya fue aceptada. Solo falta activar el formato.',
            FormatSampleStatus::Rejected => 'Pulsa “Definir mi formato”, corrige las zonas y te prepararemos una nueva muestra.',
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
