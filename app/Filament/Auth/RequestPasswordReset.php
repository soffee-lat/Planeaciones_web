<?php

namespace App\Filament\Auth;

use Filament\Notifications\Notification;
use Illuminate\Contracts\Support\Htmlable;

class RequestPasswordReset extends \Filament\Auth\Pages\PasswordReset\RequestPasswordReset
{
    public function request(): void
    {
        parent::request();

        // Keep both the message and the form state identical for unknown accounts.
        $this->form->fill();
    }

    protected function getCredentialsFromFormData(array $data): array
    {
        return ['email' => mb_strtolower(trim($data['email']))];
    }

    public function getTitle(): string | Htmlable
    {
        return filament()->getCurrentPanel()?->getId() === 'admin'
            ? 'Recuperar acceso administrativo'
            : 'Recupera tu acceso | Planeaciones Soffee';
    }

    public function getHeading(): string | Htmlable | null
    {
        return filament()->getCurrentPanel()?->getId() === 'admin'
            ? 'Recuperar acceso'
            : 'Vuelve a tu espacio docente';
    }

    protected function getFailureNotification(string $status): ?Notification
    {
        return $this->genericNotification();
    }

    protected function getSentNotification(string $status): ?Notification
    {
        return $this->genericNotification();
    }

    private function genericNotification(): Notification
    {
        return Notification::make()
            ->title('Si la cuenta existe, recibirás un enlace para restablecer tu contraseña.')
            ->success();
    }
}
