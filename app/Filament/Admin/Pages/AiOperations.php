<?php

namespace App\Filament\Admin\Pages;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\RoleCode;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use Illuminate\Support\Collection;

class AiOperations extends \Filament\Pages\Page
{
    protected static ?string $title = 'Operación IA manual';

    protected static ?string $navigationLabel = 'Operación IA';

    protected static ?string $slug = 'ai-operations';

    protected string $view = 'filament.admin.pages.ai-operations';

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

        $count = static::waitingManualQuery()->count();

        return $count > 0 ? (string) $count : null;
    }

    public function pendingExecutions(): Collection
    {
        return static::waitingManualQuery()
            ->with([
                'manualPackage',
                'request.owner',
                'request.group',
            ])
            ->orderBy('created_at')
            ->get();
    }

    public function recentExecutions(): Collection
    {
        return AiExecution::query()
            ->where('mode', AiExecutionMode::Manual->value)
            ->where('status', AiExecutionStatus::Succeeded->value)
            ->whereIn('stage', [
                AiExecutionStage::Generation->value,
                AiExecutionStage::Audit->value,
                AiExecutionStage::Correction->value,
            ])
            ->with(['request.owner'])
            ->latest('finished_at')
            ->limit(12)
            ->get();
    }

    public function pendingOutboxCount(): int
    {
        return OutboxEvent::query()
            ->whereNull('published_at')
            ->where('available_at', '<=', now())
            ->where(function ($query): void {
                $query->whereNull('lease_expires_at')
                    ->orWhere('lease_expires_at', '<=', now());
            })
            ->count();
    }

    public function stageLabel(AiExecutionStage $stage): string
    {
        return match ($stage) {
            AiExecutionStage::Generation => 'Generación',
            AiExecutionStage::Audit => 'Auditoría',
            AiExecutionStage::Correction => 'Corrección',
            default => ucfirst(str_replace('_', ' ', $stage->value)),
        };
    }

    private static function waitingManualQuery()
    {
        return AiExecution::query()
            ->where('mode', AiExecutionMode::Manual->value)
            ->where('status', AiExecutionStatus::WaitingManual->value)
            ->whereIn('stage', [
                AiExecutionStage::Generation->value,
                AiExecutionStage::Audit->value,
                AiExecutionStage::Correction->value,
            ])
            ->whereHas('manualPackage');
    }
}
