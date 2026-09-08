<?php
namespace App\Filament\Auth;
use App\Actions\Identity\RegisterCustomer;
use Illuminate\Database\Eloquent\Model;
class Register extends \Filament\Auth\Pages\Register {
    protected function handleRegistration(array $data): Model {
        return app(RegisterCustomer::class)->execute($data);
    }
}

