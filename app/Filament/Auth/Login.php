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
        return filament()->getCurrentPanel()?->getId() === 'admin'
            ? 'Acceso administrativo'
            : 'Inicia sesión | Planeaciones Soffee';
    }

    public function getHeading(): string | Htmlable | null
    {
        if (filled($this->userUndertakingMultiFactorAuthentication)) {
            return parent::getHeading();
        }

        return filament()->getCurrentPanel()?->getId() === 'admin'
            ? 'Acceso administrativo'
            : 'Accede a tu espacio docente';
    }
}
