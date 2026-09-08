<?php
namespace App\Http\Middleware;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
class EnsureActivePanelAccess {
    public function handle(Request $request, Closure $next) {
        $user = $request->user();
        abort_unless($user && $user->canAccessPanel(Filament::getCurrentPanel()), 403);
        return $next($request);
    }
}

