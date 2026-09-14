<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\Support\Colors\Color;
class AppPanelProvider extends BasePanelProvider {
    public function panel(Panel $panel): Panel {
        return $this->base($panel)->id('app')->path('app')
            ->brandName('Planeaciones · Docentes')
            ->colors(['primary' => Color::Blue])
            ->default()->registration(\App\Filament\Auth\Register::class)
            ->pages([
                \App\Filament\App\Pages\Dashboard::class,
                \App\Filament\App\Pages\StartPlanning::class,
                \App\Filament\App\Pages\Onboarding::class,
                \App\Filament\App\Pages\MyPlan::class,
            ])
            ->resources([
                \App\Filament\App\Resources\Schools\SchoolResource::class,
                \App\Filament\App\Resources\Groups\GroupResource::class,
                \App\Filament\Resources\InstitutionalFormats\InstitutionalFormatResource::class,
                \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::class,
            ]);
    }
}
