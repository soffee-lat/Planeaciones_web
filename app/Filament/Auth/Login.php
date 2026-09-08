<?php
namespace App\Filament\Auth;
class Login extends \Filament\Auth\Pages\Login {
    protected function getCredentialsFromFormData(array $data): array {
        return ['email' => mb_strtolower(trim($data['email'])), 'password' => $data['password']];
    }
}
