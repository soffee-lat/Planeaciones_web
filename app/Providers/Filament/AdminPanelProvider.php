<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\Support\Colors\Color;
class AdminPanelProvider extends BasePanelProvider {
    public function panel(Panel $panel): Panel {
        return $this->base($panel)->id('admin')->path('admin')
            ->brandName('Planeaciones · Administración')
            ->colors(['primary' => Color::Amber])
            
            ->pages([\App\Filament\Admin\Pages\Dashboard::class]);
    }
}

