<?php
namespace App\Actions\Identity;
use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
class RegisterCustomer
{
    public function execute(array $input): User {
        $input['email'] = mb_strtolower(trim($input['email'] ?? ''));
        $data = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', Password::min(12)],
        ], [
            'name.required' => 'Escribe tu nombre.',
            'name.string' => 'El nombre no es válido.',
            'name.max' => 'El nombre no puede superar los :max caracteres.',
            'email.required' => 'Escribe tu correo electrónico.',
            'email.email' => 'Escribe un correo electrónico válido.',
            'email.max' => 'El correo electrónico no puede superar los :max caracteres.',
            'email.unique' => 'Ya existe una cuenta con este correo electrónico.',
            'password.required' => 'Escribe una contraseña.',
            'password.min' => 'La contraseña debe tener al menos :min caracteres.',
        ])->validate();
        return DB::transaction(function () use ($data) {
            $user = User::create($data);
            $user->roles()->attach(Role::where('code', RoleCode::Customer->value)->firstOrFail());
            return $user;
        });
    }
}

