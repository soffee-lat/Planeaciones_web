<?php

namespace App\Filament\Auth;

use App\Actions\Identity\RegisterCustomer;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Validation\Rules\Password;

class Register extends \Filament\Auth\Pages\Register
{
    protected function handleRegistration(array $data): Model
    {
        return app(RegisterCustomer::class)->execute($data);
    }

    protected function getPasswordFormComponent(): Component
    {
        return TextInput::make('password')
            ->label(__('filament-panels::auth/pages/register.form.password.label'))
            ->password()
            ->revealable(filament()->arePasswordsRevealable())
            ->required()
            ->rule(Password::min(8))
            ->showAllValidationMessages()
            ->same('passwordConfirmation')
            ->validationMessages([
                'min' => 'La contraseña debe tener al menos 8 caracteres.',
            ])
            ->validationAttribute(__('filament-panels::auth/pages/register.form.password.validation_attribute'));
    }
}
