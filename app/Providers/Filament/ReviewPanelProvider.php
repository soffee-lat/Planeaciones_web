<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\Support\Colors\Color;
class ReviewPanelProvider extends BasePanelProvider {
    public function panel(Panel $panel): Panel {
        return $this->base($panel)->id('review')->path('review')
            ->brandName('Planeaciones · Revisión')
            ->colors(['primary' => Color::Indigo])
            
            ->pages([\App\Filament\Review\Pages\Dashboard::class]);
    }
}

