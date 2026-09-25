<?php

namespace App\Filament\Resources\PlanningRequests\Pages;

use App\Actions\Planning\RejectClientCorrection;
use App\Actions\Planning\StartClientCorrection;
use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Exceptions\ClientCorrectionException;
use App\Filament\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\CorrectionRequest;
use Filament\Actions\Action;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\ViewRecord;

class ViewPlanningRequest extends ViewRecord
{
    protected static string $resource = PlanningRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            Action::make('startClientCorrection')
                ->label('Aceptar revisión y enviar a Corrección IA')
                ->color('warning')
                ->requiresConfirmation()
                ->modalDescription('Al confirmar, se consumirá una ronda de corrección incluida y Soffee preparará un paquete de Corrección IA. Después de aplicar el cambio, la nueva versión deberá pasar una reauditoría antes de entregarse.')
                ->visible(fn (): bool => $this->pendingClientCorrection() !== null)
                ->action(function (): void {
                    try {
                        $correction = $this->pendingClientCorrection();
                        if (! $correction) {
                            throw new ClientCorrectionException('CLIENT_CORRECTION_START_STATE_INVALID');
                        }
                        app(StartClientCorrection::class)->execute($correction, auth()->user());
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Revisión aceptada')->body('La solicitud ya pasó a Corrección IA. Operación IA te avisará cuando el paquete esté listo para descargar.')->send();
                    } catch (ClientCorrectionException $error) {
                        Notification::make()->warning()->title('No se pudo iniciar la corrección')->body($error->userMessage())->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo iniciar la corrección')->body('La solicitud se conserva. Revisa el pipeline o inténtalo de nuevo.')->send();
                    }
                }),
            Action::make('rejectClientCorrection')
                ->label('Rechazar solicitud de revisión')
                ->color('danger')
                ->schema([
                    Textarea::make('resolution')
                        ->label('Motivo del rechazo')
                        ->required()
                        ->minLength(5)
                        ->maxLength(4000)
                        ->rows(4),
                ])
                ->visible(fn (): bool => $this->pendingClientCorrection() !== null)
                ->action(function (array $data): void {
                    try {
                        $correction = $this->pendingClientCorrection();
                        if (! $correction) {
                            throw new ClientCorrectionException('CLIENT_CORRECTION_REJECT_STATE_INVALID');
                        }
                        app(RejectClientCorrection::class)->execute($correction, auth()->user(), (string) $data['resolution']);
                        $this->record = $this->getRecord()->fresh();
                        Notification::make()->success()->title('Corrección rechazada')->body('La planeación volvió a su estado de entrega anterior y no consumió una ronda.')->send();
                    } catch (ClientCorrectionException $error) {
                        Notification::make()->warning()->title('No se pudo rechazar')->body($error->userMessage())->send();
                    } catch (\Throwable $error) {
                        report($error);
                        Notification::make()->danger()->title('No se pudo rechazar')->body('La solicitud se conserva sin cambios.')->send();
                    }
                }),
        ];
    }

    private function pendingClientCorrection(): ?CorrectionRequest
    {
        return $this->getRecord()->correctionRequests()
            ->where('type', CorrectionRequestType::Client->value)
            ->where('status', CorrectionRequestStatus::Requested->value)
            ->latest('id')
            ->first();
    }
}
