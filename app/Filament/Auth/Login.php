<?php

namespace App\Filament\Auth;

use Illuminate\Contracts\Support\Htmlable;

class Login extends \Filament\Auth\Pages\Login
{
    protected function getCredentialsFromFormData(array $data): array
    {
        return [
            'email' => mb_strtolower(trim($data['email'])),
            'password' => $data['password'],
        ];
    }

    public function getTitle(): string | Htmlable
    {
        return match (filament()->getCurrentPanel()?->getId()) {
            'admin' => 'Acceso administrativo',
            'review' => 'Acceso de revisión',
            default => 'Inicia sesión | Planeaciones Soffee',
        };
    }

    public function getHeading(): string | Htmlable | null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return match (filament()->getCurrentPanel()?->getId()) {
            'admin' => 'Acceso administrativo',
            'review' => 'Acceso de revisión',
            default => 'Accede a tu espacio docente',
        };
    }
}
