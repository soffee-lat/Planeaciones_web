<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LoginResponse as LoginResponseContract;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request): RedirectResponse | Redirector
    {
        if (Filament::getCurrentOrDefaultPanel()->getId() === 'app') {
            return redirect()->to(route('filament.app.pages.dashboard'));
        }

        return redirect()->intended(Filament::getUrl());
    }
}
