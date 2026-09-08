<?php
namespace App\Filament\Admin\Pages;
class Dashboard extends \Filament\Pages\Dashboard {
    protected static ?string $title = 'Administración';
    protected static ?string $navigationLabel = 'Inicio';
    protected string $view = 'filament.admin.pages.dashboard';
}

