<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\Documents\DispatchDocumentRendering;
use App\Actions\Documents\PublishPlanningDelivery;
use App\Actions\Planning\AuthorizePlanningRequestForProcessing;
use App\Actions\Planning\StartPlanningInputRevision;
use App\Actions\Planning\RequestClientCorrection;
use App\Actions\Planning\WithdrawClientCorrection;
use App\Actions\Validation\SubmitPilotFeedback;
use App\Enums\CorrectionRequestStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\ClientCorrectionException;
use App\Exceptions\PlanningCommercialException;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Filament\App\Pages\StartPlanning;
use App\Models\CorrectionRequest;
use App\Models\DocumentRenderRun;
use App\Models\DocumentVersion;
use App\Models\PlanningRequest;
use App\Services\Planning\ClientCorrectionPolicy;
use Filament\Actions\Action;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Model;

class ViewPlanningRequest extends ViewRecord
{
    protected static string $resource = PlanningRequestResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            View::make('filament.app.planning-requests.deliveries')->viewData(fn () => [
                'deliveries' => $this->getRecord()->deliveries()->with(['files', 'version'])->get(),
            ])->visible(fn () => $this->getRecord()->deliveries()->exists())->columnSpanFull(),

            View::make('filament.app.planning-requests.pilot-feedback')
                ->viewData(fn (): array => [
                    'request' => $this->getRecord(),
                    'feedback' => $this->getRecord()->feedback()->first(),
                    'savedTimeOptions' => SubmitPilotFeedback::SAVED_TIME_OPTIONS,
                    'helpfulOptions' => SubmitPilotFeedback::MOST_HELPFUL_OPTIONS,
                    'nextPlanningOptions' => SubmitPilotFeedback::NEXT_PLANNING_OPTIONS,
                ])
                ->visible(fn (): bool => $this->getRecord()->creation_mode === 'quick' && $this->getRecord()->deliveries()->exists())
                ->columnSpanFull(),

            Section::make('Seguimiento')->schema([
                TextEntry::make('project')->label('Tema o proyecto'),
                TextEntry::make('status')->label('Estado')->state(fn () => app(\App\Services\Commerce\PlanningCommercialPresentation::class)->status($this->getRecord())),
                TextEntry::make('group.name')->label('Grupo'),
                TextEntry::make('starts_on')->label('Inicio')->date('d/m/Y'),
                TextEntry::make('ends_on')->label('Fin')->date('d/m/Y'),
                TextEntry::make('planning_units')->label('Unidades reservadas')->placeholder('Aún no se reservan unidades'),
                TextEntry::make('correction_limit_snapshot')->label('Rondas de corrección incluidas por solicitud')->visible(fn () => $this->getRecord()->commercial_authorized_at !== null),
                TextEntry::make('correction_rounds_remaining')
                    ->label('Rondas de corrección disponibles')
                    ->state(fn (): int => app(ClientCorrectionPolicy::class)->remainingRounds($this->getRecord()))
                    ->visible(fn () => $this->getRecord()->commercial_authorized_at !== null && (int) $this->getRecord()->correction_limit_snapshot > 0),
                TextEntry::make('correction_window_ends_at')
                    ->label('Solicita correcciones hasta')
                    ->state(fn () => app(ClientCorrectionPolicy::class)->windowEndsAt($this->getRecord()))
                    ->dateTime('d/m/Y H:i')
                    ->placeholder('Disponible después de la primera entrega')
                    ->visible(fn () => $this->getRecord()->deliveries()->exists() && (int) $this->getRecord()->correction_limit_snapshot > 0),
                TextEntry::make('human_review_required_snapshot')->label('Revisión humana incluida')->formatStateUsing(fn ($state) => $state ? 'Sí' : 'No')->visible(fn () => $this->getRecord()->commercial_authorized_at !== null),
            ])->columns(2)->columnSpanFull(),

            Section::make('Se necesita una revisión antes de continuar')
                ->description('La planeación sí tiene una versión generada, pero la auditoría detectó que algunos temas no están suficientemente respaldados por los contenidos/PDA seleccionados. No seguiremos corrigiendo automáticamente porque eso podría cambiar lo que pediste o inventar referencias curriculares.')
                ->schema([
                    TextEntry::make('curriculum_revision_notice')
                        ->hiddenLabel()
                        ->state('Revisa los temas y la selección curricular; después se generará una nueva versión conservando el historial anterior.'),
                ])
                ->visible(fn (): bool => app(\App\Services\Commerce\PlanningCommercialPresentation::class)
                    ->requiresCurriculumInputRevision($this->getRecord()))
                ->columnSpanFull(),

            View::make('filament.app.pages.commercial-summary')->viewData(fn () => [
                'summary' => app(\App\Services\Commerce\PlanningCommercialPresentation::class)->forCustomer(auth()->user(), $this->getRecord()->starts_on?->toDateString(), $this->getRecord()->ends_on?->toDateString()),
            ])->visible(fn () => $this->getRecord()->status === PlanningRequestStatus::ESPERANDO_PAGO)->columnSpanFull(),

            View::make('filament.app.planning-requests.generated-preview')
                ->viewData(function (): array {
                    $version = $this->currentVersion();
                    return [
                        'plan' => $version?->content ?? [],
                        'version' => $version,
                        'renderRun' => $this->latestSuccessfulRender(),
                    ];
                })
                ->visible(fn () => $this->currentVersion() !== null)
                ->columnSpanFull(),

        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('reviseCurriculumInputs')
                ->label('Revisar temas y currículo')
                ->icon('heroicon-o-pencil-square')
                ->color('warning')
                ->visible(fn (): bool => app(\App\Services\Commerce\PlanningCommercialPresentation::class)
                    ->requiresCurriculumInputRevision($this->getRecord()))
                ->action(function (): void {
                    try {
                        app(StartPlanningInputRevision::class)->execute(auth()->user(), $this->getRecord());
                        $this->redirect(StartPlanning::getUrl() . '?draft=' . $this->getRecord()->id);
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()
                            ->title('No se pudo abrir la revisión')
                            ->body('La planeación se conserva sin cambios. Recarga e inténtalo nuevamente.')
                            ->send();
                    }
                }),

            Action::make('activateProcessing')->label('Activar procesamiento')->databaseTransaction(false)
                ->visible(fn () => $this->getRecord()->status === PlanningRequestStatus::ESPERANDO_PAGO)
                ->action(function (): void {
                    try {
                        app(AuthorizePlanningRequestForProcessing::class)->execute(auth()->user(), $this->getRecord());
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Unidades reservadas')->body('Tu planeación está en Preparando.')->send();
                    } catch (PlanningCommercialException $error) {
                        Notification::make()->warning()->title('Pendiente de activar')->body($error->userMessage())->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No pudimos activar el procesamiento')->body('Tu solicitud se conserva. Inténtalo de nuevo o solicita ayuda.')->send();
                    }
                }),

            Action::make('generatePlanning')
                ->label('Generar planeación')
                ->color('primary')
                ->databaseTransaction(false)
                ->visible(fn () => $this->getRecord()->status === PlanningRequestStatus::LISTA_PARA_PROCESAR)
                ->action(function (): void {
                    try {
                        app(DispatchPlanningGeneration::class)->execute($this->getRecord());
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Generación iniciada')
                            ->body('Generaremos primero el contenido pedagógico. El formato elegido se aplicará después de la aprobación y podrás confirmarlo o cambiarlo antes de exportar.')->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo iniciar la generación')
                            ->body('La solicitud se conserva sin cambios irreversibles. Revisa la configuración del pipeline y vuelve a intentarlo.')->send();
                    }
                }),

            Action::make('renderDocument')
                ->label('Exportar planeación')
                ->color('primary')
                ->databaseTransaction(false)
                ->modalHeading('Exportar planeación')
                ->modalDescription('El contenido pedagógico ya está aprobado. Conservamos el formato que elegiste al crear la planeación; aquí puedes confirmarlo o cambiarlo antes de exportar. Esto no vuelve a generar la planeación ni consume otra generación de IA.')
                ->schema([
                    Select::make('format_version_id')
                        ->label('Formato de salida')
                        ->options(fn () => PlanningRequestResource::formatVersionOptions())
                        ->default(fn () => $this->defaultExportFormatVersionId())
                        ->required()
                        ->searchable()
                        ->native(false)
                        ->helperText('El formato general sigue siendo el predeterminado cuando no eliges otro. Puedes cambiar la salida sin modificar el contenido pedagógico.'),
                ])
                ->visible(fn () => $this->getRecord()->status === PlanningRequestStatus::APROBADA)
                ->action(function (array $data): void {
                    try {
                        $formatId = (int) ($data['format_version_id'] ?? 0);
                        $options = PlanningRequestResource::formatVersionOptions();
                        if ($formatId < 1 || ! array_key_exists($formatId, $options)) {
                            Notification::make()->warning()->title('Formato no disponible')->body('Selecciona un formato válido para tu cuenta.')->send();
                            return;
                        }

                        $record = $this->getRecord();
                        $record->forceFill(['format_version_id' => $formatId])->save();

                        app(DispatchDocumentRendering::class)->execute($record->fresh());
                        $this->record = $record->fresh();
                        Notification::make()->success()->title('Documentos en preparación')
                            ->body('Estamos aplicando el formato elegido sobre la planeación ya aprobada.')->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudieron preparar los documentos')
                            ->body('La planeación aprobada se conserva. Revisa el formato y vuelve a intentarlo.')->send();
                    }
                }),

            Action::make('publishDelivery')
                ->label('Publicar entrega')
                ->color('success')
                ->databaseTransaction(false)
                ->visible(fn () => $this->getRecord()->status === PlanningRequestStatus::LISTA_PARA_ENTREGAR)
                ->action(function (): void {
                    try {
                        app(PublishPlanningDelivery::class)->execute($this->getRecord(), auth()->user());
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Planeación lista')
                            ->body('El DOCX y el PDF ya están disponibles en esta pantalla.')->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo publicar la entrega')
                            ->body('Los archivos se conservan. Recarga la pantalla e inténtalo de nuevo.')->send();
                    }
                }),

            Action::make('requestCorrection')
                ->label('Solicitar corrección')
                ->color('warning')
                ->schema([
                    Select::make('reason')
                        ->label('Motivo principal')
                        ->options(ClientCorrectionPolicy::REASONS)
                        ->required(),
                    CheckboxList::make('section_keys')
                        ->label('¿Qué partes necesitan ajuste?')
                        ->options(ClientCorrectionPolicy::SECTION_OPTIONS)
                        ->required()
                        ->columns(1),
                    Textarea::make('description')
                        ->label('Describe exactamente qué necesitas cambiar')
                        ->helperText('La corrección mantiene el mismo currículo, contexto, fechas y alcance contratado.')
                        ->required()
                        ->minLength(10)
                        ->maxLength(4000)
                        ->rows(5),
                ])
                ->visible(fn (): bool => in_array($this->getRecord()->status, [PlanningRequestStatus::ENTREGADA, PlanningRequestStatus::COMPLETADA], true)
                    && (int) $this->getRecord()->correction_limit_snapshot > 0)
                ->action(function (array $data): void {
                    try {
                        app(RequestClientCorrection::class)->execute(
                            auth()->user(),
                            $this->getRecord(),
                            (string) $data['reason'],
                            (string) $data['description'],
                            array_map('strval', $data['section_keys'] ?? []),
                        );
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Corrección solicitada')->body('Conservas la entrega anterior mientras procesamos el ajuste.')->send();
                    } catch (ClientCorrectionException $error) {
                        Notification::make()->warning()->title('No se pudo solicitar la corrección')->body($error->userMessage())->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo solicitar la corrección')->body('Tu entrega se conserva sin cambios. Inténtalo de nuevo o solicita ayuda.')->send();
                    }
                }),
            Action::make('withdrawCorrection')
                ->label('Retirar solicitud de corrección')
                ->color('gray')
                ->requiresConfirmation()
                ->modalDescription('La planeación volverá al estado que tenía antes de solicitar la corrección y no se consumirá una ronda.')
                ->visible(fn (): bool => $this->pendingClientCorrection() !== null)
                ->action(function (): void {
                    try {
                        $correction = $this->pendingClientCorrection();
                        if (! $correction) {
                            throw new ClientCorrectionException('CLIENT_CORRECTION_WITHDRAW_STATE_INVALID');
                        }
                        app(WithdrawClientCorrection::class)->execute($correction, auth()->user());
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Solicitud retirada')->body('Tu entrega anterior sigue disponible.')->send();
                    } catch (ClientCorrectionException $error) {
                        Notification::make()->warning()->title('No se pudo retirar')->body($error->userMessage())->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo retirar')->body('Recarga la planeación e inténtalo nuevamente.')->send();
                    }
                }),
        ];
    }

    private function defaultExportFormatVersionId(): ?int
    {
        return PlanningRequestResource::exportFormatVersionIdFor($this->getRecord());
    }

    private function currentVersion(): ?DocumentVersion
    {
        $document = $this->getRecord()->document()->with('currentVersion')->first();
        return $document?->currentVersion;
    }

    private function latestSuccessfulRender(): ?DocumentRenderRun
    {
        return $this->getRecord()->documentRenderRuns()
            ->where('status', 'succeeded')
            ->latest('id')
            ->first();
    }

    private function pendingClientCorrection(): ?CorrectionRequest
    {
        if ($this->getRecord()->status !== PlanningRequestStatus::CORRECCION_SOLICITADA) {
            return null;
        }

        return $this->getRecord()->correctionRequests()
            ->where('requester_id', auth()->id())
            ->where('status', CorrectionRequestStatus::Requested->value)
            ->latest('id')
            ->first();
    }

    protected function resolveRecord(int|string $key): Model
    {
        return PlanningRequest::query()->where('owner_id', auth()->id())->findOrFail($key);
    }
}
