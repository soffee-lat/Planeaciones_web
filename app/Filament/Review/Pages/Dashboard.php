<?php
namespace App\Filament\Review\Pages;

use App\Services\Review\ReviewerMetricsService;

class Dashboard extends \Filament\Pages\Dashboard {
    protected static ?string $title = 'Espacio de revisión';
    protected static ?string $navigationLabel = 'Inicio';
    protected string $view = 'filament.review.pages.dashboard';

    public function getViewData(): array
    {
        return [
            'reviewerMetrics' => app(ReviewerMetricsService::class)->snapshot(auth()->user()),
        ];
    }
}
