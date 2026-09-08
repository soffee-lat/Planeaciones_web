<?php
namespace App\Filament\App\Pages;
class Dashboard extends \Filament\Pages\Dashboard {
    protected static ?string $title = 'Mi espacio docente';
    protected static ?string $navigationLabel = 'Inicio';
    protected string $view = 'filament.app.pages.dashboard';
}

