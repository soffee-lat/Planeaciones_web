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
                ->label('Confirmar y congelar snapshot')
                ->color('warning')
                ->requiresConfirmation()
                ->visible(fn () => $this->getRecord()?->isDraft() ?? false)
                ->action(function () {
                    try {
                        app(ConfirmPlanningRequest::class)->execute(auth()->user(), $this->getRecord());
                        Notification::make()->success()->title('Solicitud confirmada')
                            ->body('Se congeló el snapshot. Continuará cuando exista plan/cupo (fase comercial).')
                            ->send();
                        $this->redirect(PlanningRequestResource::getUrl('view', ['record' => $this->getRecord()->id]));
                    } catch (\Throwable $e) {
                        Notification::make()->danger()->title('No se pudo confirmar')
                            ->body($e->getMessage())->send();
                    }
                }),
        ];
    }
}
