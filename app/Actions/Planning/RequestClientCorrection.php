<?php

namespace App\Actions\Planning;

use App\Actions\Notifications\QueueOperationalNotification;
use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Enums\OperationalNotificationType;
use App\Enums\PlanningRequestStatus;
use App\Enums\RoleCode;
use App\Exceptions\ClientCorrectionException;
use App\Models\CorrectionRequest;
use App\Models\PlanningDelivery;
use App\Models\PlanningRequest;
use App\Models\User;
use App\Services\Planning\ClientCorrectionPolicy;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Throwable;

final class RequestClientCorrection
{
    public function __construct(
        private ClientCorrectionPolicy $policy,
        private PlanningRequestStateMachine $stateMachine,
        private QueueOperationalNotification $notifications,
    ) {}

    /** @param list<string> $sectionKeys */
    public function execute(
        User $actor,
        PlanningRequest|int $request,
        string $reason,
        string $description,
        array $sectionKeys,
        ?string $correlationId = null,
    ): CorrectionRequest {
        $requestId = $request instanceof PlanningRequest ? $request->id : $request;
        $normalized = $this->policy->normalize($reason, $description, $sectionKeys);
        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_CORRELATION_INVALID');
        }
        $correlationId = strtolower($correlationId);

        $correction = DB::transaction(function () use ($actor, $requestId, $normalized, $correlationId): CorrectionRequest {
            $locked = PlanningRequest::query()->whereKey($requestId)->lockForUpdate()->firstOrFail();
            Gate::forUser($actor)->authorize('requestCorrection', $locked);

            $open = CorrectionRequest::query()
                ->where('request_id', $locked->id)
                ->whereIn('status', [
                    CorrectionRequestStatus::Requested->value,
                    CorrectionRequestStatus::Accepted->value,
                    CorrectionRequestStatus::Processing->value,
                ])
                ->lockForUpdate()
                ->first();
            if ($open) {
                $samePayload = (int) $open->requester_id === (int) $actor->id
                    && $open->reason === $normalized['reason']
                    && $open->description === $normalized['description']
                    && $open->section_keys === $normalized['section_keys'];
                if ($samePayload && $locked->status === PlanningRequestStatus::CORRECCION_SOLICITADA) {
                    return $open;
                }
                throw new ClientCorrectionException('CLIENT_CORRECTION_ALREADY_OPEN');
            }

            if (! in_array($locked->status, [PlanningRequestStatus::ENTREGADA, PlanningRequestStatus::COMPLETADA], true)) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_REQUEST_STATE_INVALID');
            }

            $delivery = PlanningDelivery::query()
                ->where('request_id', $locked->id)
                ->orderByDesc('delivered_at')
                ->orderByDesc('id')
                ->lockForUpdate()
                ->first();
            if (! $delivery) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_DELIVERY_REQUIRED');
            }
            $this->policy->assertRequestable($locked, $delivery);

            $correction = CorrectionRequest::query()->create([
                'request_id' => $locked->id,
                'delivered_version_id' => $delivery->version_id,
                'source_version_id' => $delivery->version_id,
                'requester_id' => $actor->id,
                'type' => CorrectionRequestType::Client->value,
                'reason' => $normalized['reason'],
                'description' => $normalized['description'],
                'section_keys' => $normalized['section_keys'],
                'status' => CorrectionRequestStatus::Requested->value,
                'assigned_to' => null,
                'requested_at' => now(),
                'resolved_at' => null,
                'resolution' => null,
                'resulting_version_id' => null,
            ]);

            $from = $locked->status;
            $this->stateMachine->assertCanTransition($from, PlanningRequestStatus::CORRECCION_SOLICITADA);
            $locked->forceFill([
                'status' => PlanningRequestStatus::CORRECCION_SOLICITADA->value,
                'lock_version' => (int) $locked->lock_version + 1,
            ])->save();
            $locked->stateEvents()->create([
                'from_status' => $from->value,
                'to_status' => PlanningRequestStatus::CORRECCION_SOLICITADA->value,
                'actor_id' => $actor->id,
                'actor_type' => 'user',
                'reason' => 'client_correction_requested',
                'correlation_id' => $correlationId,
            ]);

            return $correction->fresh(['request', 'sourceVersion', 'requester']);
        }, attempts: 3);

        $this->notifyAdministrators($correction);

        return $correction;
    }

    private function notifyAdministrators(CorrectionRequest $correction): void
    {
        $correction->loadMissing(['request.owner', 'requester']);
        $request = $correction->request;
        $requester = $correction->requester;

        $administrators = User::query()
            ->where('status', 'active')
            ->whereHas('roles', fn ($query) => $query->where('code', RoleCode::Administrator->value))
            ->get();

        foreach ($administrators as $administrator) {
            try {
                $this->notifications->execute(
                    OperationalNotificationType::ClientCorrectionRequested,
                    $administrator,
                    sprintf('client-correction:%d:requested:admin:%d', $correction->id, $administrator->id),
                    'correction_request',
                    (int) $correction->id,
                    [
                        'title' => 'Nueva revisión solicitada por un cliente',
                        'body' => sprintf(
                            '%s solicitó revisar la planeación #%d%s. Revisa el motivo y decide si se procesa o se rechaza.',
                            $requester?->name ?: 'Un cliente',
                            (int) $correction->request_id,
                            $request?->project ? ' · ' . $request->project : '',
                        ),
                        'url' => '/admin/client-corrections',
                    ],
                );
            } catch (Throwable $error) {
                // La solicitud del cliente ya quedó registrada. Una falla al
                // generar el aviso no debe hacerle creer que la operación falló.
                report($error);
            }
        }
    }
}
