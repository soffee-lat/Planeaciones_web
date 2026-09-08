<?php
namespace App\Providers\Filament;
use Filament\Panel;
use Filament\PanelProvider;
abstract class BasePanelProvider extends PanelProvider {
    protected function base(Panel $panel): Panel {
        return $panel->login(\App\Filament\Auth\Login::class)->passwordReset(\App\Filament\Auth\RequestPasswordReset::class)->emailVerification()->emailChangeVerification()->profile(\App\Filament\Auth\EditProfile::class)
            ->brandName('Planeaciones')->viteTheme('resources/css/filament/theme.css')->databaseTransactions()
            ->middleware([
                \Illuminate\Cookie\Middleware\EncryptCookies::class,
                \Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse::class,
                \Illuminate\Session\Middleware\StartSession::class,
                \Filament\Http\Middleware\AuthenticateSession::class,
                \Illuminate\View\Middleware\ShareErrorsFromSession::class,
                \Illuminate\Foundation\Http\Middleware\ValidateCsrfToken::class,
                \Illuminate\Routing\Middleware\SubstituteBindings::class,
                \Filament\Http\Middleware\DisableBladeIconComponents::class,
                \Filament\Http\Middleware\DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                \Filament\Http\Middleware\Authenticate::class,
                \App\Http\Middleware\EnsureActivePanelAccess::class,
            ], isPersistent: true);
    }
}





