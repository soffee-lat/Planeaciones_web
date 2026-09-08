<?php

namespace App\Filament\App\Resources\PlanningRequests\Pages;

use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use Filament\Resources\Pages\ViewRecord;
use Illuminate\Database\Eloquent\Model;
use App\Actions\Planning\AuthorizePlanningRequestForProcessing;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\PlanningCommercialException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Schemas\Schema;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\View;
use Filament\Infolists\Components\TextEntry;

class ViewPlanningRequest extends ViewRecord
{
    protected static string $resource = PlanningRequestResource::class;

    public function infolist(Schema $schema): Schema
    {
        return $schema->components([
            Section::make('Seguimiento')->schema([
                TextEntry::make('project')->label('Tema o proyecto'),
                TextEntry::make('status')->label('Estado')->state(fn () => app(\App\Services\Commerce\PlanningCommercialPresentation::class)->status($this->getRecord())),
                TextEntry::make('group.name')->label('Grupo'),
                TextEntry::make('starts_on')->label('Inicio')->date('d/m/Y'),
                TextEntry::make('ends_on')->label('Fin')->date('d/m/Y'),
                TextEntry::make('planning_units')->label('Unidades reservadas')->placeholder('Aún no se reservan unidades'),
                TextEntry::make('correction_limit_snapshot')->label('Rondas de corrección incluidas por solicitud')->visible(fn () => $this->getRecord()->commercial_authorized_at !== null),
                TextEntry::make('human_review_required_snapshot')->label('Revisión humana incluida')->formatStateUsing(fn ($state) => $state ? 'Sí' : 'No')->visible(fn () => $this->getRecord()->commercial_authorized_at !== null),
            ])->columns(2)->columnSpanFull(),
            View::make('filament.app.pages.commercial-summary')->viewData(fn () => [
                'summary' => app(\App\Services\Commerce\PlanningCommercialPresentation::class)->forCustomer(auth()->user(), $this->getRecord()->starts_on?->toDateString(), $this->getRecord()->ends_on?->toDateString()),
            ])->visible(fn () => $this->getRecord()->status === PlanningRequestStatus::ESPERANDO_PAGO)->columnSpanFull(),
        ]);
    }

    protected function getHeaderActions(): array
    {
        return [
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
        ];
    }

    protected function resolveRecord(int|string $key): Model
    {
        return PlanningRequest::query()->where('owner_id', auth()->id())->findOrFail($key);
    }
}
