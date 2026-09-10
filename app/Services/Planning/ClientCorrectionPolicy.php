<?php

namespace App\Services\Planning;

use App\Data\AI\AuditFinding;
use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\ClientCorrectionException;
use App\Models\CorrectionRequest;
use App\Models\PlanningDelivery;
use App\Models\PlanningRequest;
use App\Models\UsageReservation;
use App\Support\AI\CanonicalJson;
use Carbon\CarbonInterface;

final class ClientCorrectionPolicy
{
    /** @var array<string,string> */
    public const REASONS = [
        'wording' => 'Redacción o claridad',
        'activities' => 'Actividades o sesiones',
        'assessment' => 'Evaluación',
        'resources' => 'Recursos o materiales',
        'adaptation' => 'Adecuaciones',
        'other' => 'Otro ajuste dentro del mismo alcance',
    ];

    /** @var array<string,string> */
    public const SECTION_OPTIONS = [
        'planning' => 'Título o nombre del proyecto',
        'pedagogical_design' => 'Diseño pedagógico',
        'sessions' => 'Sesiones y actividades',
        'assessment_plan' => 'Evaluación',
        'resources' => 'Recursos y materiales',
        'adaptation_notes' => 'Adecuaciones y notas de adaptación',
    ];

    /** @return array{reason:string,description:string,section_keys:list<string>} */
    public function normalize(string $reason, string $description, array $sectionKeys): array
    {
        $reason = trim($reason);
        $description = trim($description);
        if (! array_key_exists($reason, self::REASONS)) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_REASON_INVALID');
        }
        if (mb_strlen($description) < 10 || mb_strlen($description) > 4000) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_DESCRIPTION_REQUIRED');
        }

        $sectionKeys = array_values(array_unique(array_map('strval', $sectionKeys)));
        sort($sectionKeys, SORT_STRING);
        if ($sectionKeys === []) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_SCOPE_REQUIRED');
        }
        foreach ($sectionKeys as $key) {
            if (! array_key_exists($key, self::SECTION_OPTIONS)) {
                throw new ClientCorrectionException('CLIENT_CORRECTION_SCOPE_INVALID');
            }
        }

        return [
            'reason' => $reason,
            'description' => $description,
            'section_keys' => $sectionKeys,
        ];
    }

    public function assertRequestable(PlanningRequest $request, PlanningDelivery $delivery): void
    {
        if ((int) $delivery->request_id !== (int) $request->id) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_DELIVERY_INVALID');
        }
        $document = $request->document()->first();
        if (! $document
            || (int) $document->current_version_id !== (int) $delivery->version_id) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_SOURCE_NOT_CURRENT');
        }

        $limit = (int) $request->correction_limit_snapshot;
        $windowDays = (int) data_get($request->calculation_snapshot, 'entitlements.correction_window_days', 0);
        if ($limit < 1 || $windowDays < 1) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_NOT_INCLUDED');
        }

        $windowEndsAt = $this->windowEndsAt($request);
        if (! $windowEndsAt || now()->gt($windowEndsAt)) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_WINDOW_EXPIRED');
        }
        if ($this->usedRounds($request) >= $limit) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_LIMIT_REACHED');
        }
    }

    public function windowEndsAt(PlanningRequest $request): ?CarbonInterface
    {
        $windowDays = (int) data_get($request->calculation_snapshot, 'entitlements.correction_window_days', 0);
        if ($windowDays < 1) {
            return null;
        }
        $firstDelivery = PlanningDelivery::query()
            ->where('request_id', $request->id)
            ->orderBy('delivered_at')
            ->orderBy('id')
            ->first();

        return $firstDelivery?->delivered_at?->copy()->addDays($windowDays);
    }

    public function usedRounds(PlanningRequest $request): int
    {
        return (int) UsageReservation::query()
            ->where('planning_request_id', $request->id)
            ->where('resource', UsageResource::ClientCorrection->value)
            ->whereIn('status', [UsageReservationStatus::Reserved->value, UsageReservationStatus::Consumed->value])
            ->sum('quantity');
    }

    public function remainingRounds(PlanningRequest $request): int
    {
        return max(0, (int) $request->correction_limit_snapshot - $this->usedRounds($request));
    }

    /** @return array{request_id:int,source_version_id:int,requester_id:int,reason:string,description:string,section_keys:list<string>,requested_at:?string} */
    public function payload(CorrectionRequest $correction): array
    {
        $keys = array_values(array_unique(array_map('strval', $correction->section_keys ?? [])));
        sort($keys, SORT_STRING);

        return [
            'request_id' => (int) $correction->request_id,
            'source_version_id' => (int) $correction->source_version_id,
            'requester_id' => (int) $correction->requester_id,
            'reason' => (string) $correction->reason,
            'description' => (string) $correction->description,
            'section_keys' => $keys,
            'requested_at' => $correction->requested_at?->toIso8601String(),
        ];
    }

    public function payloadHash(CorrectionRequest $correction): string
    {
        return CanonicalJson::hash($this->payload($correction));
    }

    /** @return list<AuditFinding> */
    public function findings(CorrectionRequest $correction): array
    {
        $label = self::REASONS[$correction->reason] ?? 'Corrección solicitada por el cliente';
        $findingCode = match ($correction->reason) {
            'wording' => 'LANGUAGE_QUALITY',
            'activities' => 'PEDAGOGICAL_ALIGNMENT',
            'assessment' => 'ASSESSMENT_ALIGNMENT',
            'resources' => 'MATERIAL_FEASIBILITY',
            'adaptation' => 'GROUP_CONTEXT',
            default => 'UNSUPPORTED_ASSUMPTION',
        };

        return array_map(
            fn (string $section): AuditFinding => new AuditFinding(
                code: $findingCode,
                severity: 'medium',
                jsonPath: '/' . $section,
                explanation: '[Solicitud del cliente] ' . $label . ': ' . $correction->description,
                expectedCorrection: 'Modificar únicamente esta sección según la solicitud del cliente, sin alterar currículo, contexto, fechas ni alcance contratado.',
            ),
            array_values(array_map('strval', $correction->section_keys ?? [])),
        );
    }

    public function assertClientProcessing(CorrectionRequest $correction, PlanningRequest $request, int $sourceVersionId): void
    {
        if ($correction->type !== CorrectionRequestType::Client
            || $correction->status !== CorrectionRequestStatus::Processing
            || (int) $correction->request_id !== (int) $request->id
            || (int) $correction->source_version_id !== $sourceVersionId
            || (int) $correction->requester_id !== (int) $request->owner_id) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_SOURCE_STALE');
        }

        $reservation = $correction->reservation;
        if (! $reservation
            || $reservation->resource !== UsageResource::ClientCorrection
            || $reservation->status !== UsageReservationStatus::Consumed
            || (int) $reservation->planning_request_id !== (int) $request->id
            || (int) $reservation->quantity !== 1) {
            throw new ClientCorrectionException('CLIENT_CORRECTION_RESERVATION_REQUIRED');
        }
    }
}
