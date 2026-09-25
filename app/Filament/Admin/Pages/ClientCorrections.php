<?php

namespace App\Filament\Admin\Pages;

use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use App\Enums\RoleCode;
use App\Filament\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\CorrectionRequest;
use App\Services\Planning\ClientCorrectionPolicy;
use Illuminate\Support\Collection;

class ClientCorrections extends \Filament\Pages\Page
{
    protected static ?string $title = 'Revisiones de clientes';

    protected static ?string $navigationLabel = 'Revisiones de clientes';

    protected static ?string $slug = 'client-corrections';

    protected string $view = 'filament.admin.pages.client-corrections';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user
            && $user->status === 'active'
            && $user->hasRole(RoleCode::Administrator);
    }

    public static function getNavigationBadge(): ?string
    {
        if (! static::canAccess()) {
            return null;
        }

        $count = static::pendingQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function pendingCorrections(): Collection
    {
        return static::pendingQuery()
            ->with([
                'request.owner',
                'request.group',
                'requester',
                'sourceVersion',
            ])
            ->orderBy('requested_at')
            ->orderBy('id')
            ->get();
    }

    public function recentCorrections(): Collection
    {
        return CorrectionRequest::query()
            ->where('type', CorrectionRequestType::Client->value)
            ->whereIn('status', [
                CorrectionRequestStatus::Accepted->value,
                CorrectionRequestStatus::Processing->value,
                CorrectionRequestStatus::Resolved->value,
                CorrectionRequestStatus::Rejected->value,
                CorrectionRequestStatus::Withdrawn->value,
            ])
            ->with(['request.owner', 'requester', 'assignee'])
            ->latest('id')
            ->limit(12)
            ->get();
    }

    public function reasonLabel(CorrectionRequest $correction): string
    {
        return ClientCorrectionPolicy::REASONS[$correction->reason] ?? $correction->reason;
    }

    public function sectionLabels(CorrectionRequest $correction): array
    {
        return collect($correction->section_keys ?? [])
            ->map(fn (string $key): string => ClientCorrectionPolicy::SECTION_OPTIONS[$key] ?? $key)
            ->values()
            ->all();
    }

    public function statusLabel(CorrectionRequestStatus $status): string
    {
        return match ($status) {
            CorrectionRequestStatus::Requested => 'Esperando decisión',
            CorrectionRequestStatus::Accepted => 'Aceptada',
            CorrectionRequestStatus::Processing => 'En corrección',
            CorrectionRequestStatus::Resolved => 'Resuelta',
            CorrectionRequestStatus::Rejected => 'Rechazada',
            CorrectionRequestStatus::Withdrawn => 'Retirada por cliente',
        };
    }

    public function requestUrl(CorrectionRequest $correction): string
    {
        return PlanningRequestResource::getUrl('view', ['record' => $correction->request_id], panel: 'admin');
    }

    private static function pendingQuery()
    {
        return CorrectionRequest::query()
            ->where('type', CorrectionRequestType::Client->value)
            ->where('status', CorrectionRequestStatus::Requested->value);
    }
}
