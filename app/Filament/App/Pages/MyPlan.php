<?php

namespace App\Filament\App\Pages;

use App\Enums\RoleCode;
use App\Services\Commerce\PlanningCommercialPresentation;
use Filament\Pages\Page;

class MyPlan extends Page
{
    protected static ?string $title = 'Mi plan';
    protected static ?string $slug = 'mi-plan';
    protected static ?int $navigationSort = 40;
    protected string $view = 'filament.app.pages.my-plan';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user && $user->status === 'active' && $user->hasVerifiedEmail() && $user->hasRole(RoleCode::Customer);
    }

    protected function getViewData(): array
    {
        return ['summary' => app(PlanningCommercialPresentation::class)->forCustomer(auth()->user())];
    }
}
