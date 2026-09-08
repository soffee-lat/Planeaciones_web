<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Arr;

class EditPlanningRequest extends EditRecord
{
    protected static string $resource = PlanningRequestResource::class;

    protected function resolveRecord(int|string $key): Model
    {
        return PlanningRequest::query()->where('owner_id', auth()->id())->findOrFail($key);
    }

    protected function mutateFormDataBeforeFill(array $data): array
    {
        /** @var PlanningRequest $record */
        $record = $this->getRecord();
        $data['selected_contents'] = $record->contents()->pluck('curricular_contents.id')->all();
        $data['selected_pdas'] = $record->pdas()->pluck('pdas.id')->all();
        $data['selected_axes'] = $record->articulatingAxes()->pluck('articulating_axes.id')->all();
        return $data;
    }

    protected function handleRecordUpdate(Model $record, array $data): Model
    {
        /** @var PlanningRequest $record */
        $contents = Arr::pull($data, 'selected_contents', []);
        $pdas = Arr::pull($data, 'selected_pdas', []);
        $axes = Arr::pull($data, 'selected_axes', []);
        unset($data['owner_id'], $data['group_id'], $data['curriculum_version_id'], $data['grade_id'], $data['status']);

        app(UpdatePlanningRequestDraft::class)->execute(auth()->user(), $record, $data);
        app(SyncPlanningRequestSelections::class)->execute(auth()->user(), $record, [
            'contents' => $contents,
            'pdas' => $pdas,
            'axes' => $axes,
        ]);

        return $record->refresh();
    }

    protected function getHeaderActions(): array
    {
        return [
            Action::make('confirm')
                ->databaseTransaction(false)
                ->label('Confirmar planeación')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Se congelará la selección curricular y el perfil pedagógico. Después no podrás editar esta planeación.')
                ->visible(fn () => $this->getRecord()?->isDraft() ?? false)
                ->action(function () {
                    try {
                        $this->save(shouldRedirect: false, shouldSendSavedNotification: false);
                        app(ConfirmPlanningRequest::class)->execute(auth()->user(), $this->getRecord());
                        $this->attemptCommercialAuthorization();
                        $this->redirect(PlanningRequestResource::getUrl('view', ['record' => $this->getRecord()->id]));
                    } catch (\Illuminate\Validation\ValidationException $e) {
                        throw $e;
                    } catch (\Throwable $e) {
                        report($e);
                        Notification::make()->danger()->title('No se pudo confirmar')
                            ->body('Revisa las fechas, el perfil del grupo y la selección de contenidos y PDA.')->send();
                    }
                }),
        ];
    }

    private function attemptCommercialAuthorization(): void
    {
        try {
            app(\App\Actions\Planning\AuthorizePlanningRequestForProcessing::class)->execute(auth()->user(), $this->getRecord());
            Notification::make()->success()->title('Tu planeación está preparando su siguiente paso')
                ->body('Las unidades quedaron reservadas. Puedes consultar el seguimiento en Mis planeaciones.')->send();
        } catch (\App\Exceptions\PlanningCommercialException $error) {
            Notification::make()->warning()->title('Solicitud confirmada · Pendiente de activar')->body($error->userMessage())->send();
        } catch (\Throwable $error) {
            report($error);
            Notification::make()->warning()->title('Tu solicitud está confirmada')
                ->body('No pudimos activar el procesamiento ahora. Puedes volver a intentarlo desde el seguimiento.')->send();
        }
    }
}
