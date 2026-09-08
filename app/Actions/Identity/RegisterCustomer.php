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
        ])->validate();
        return DB::transaction(function () use ($data) {
            $user = User::create($data);
            $user->roles()->attach(Role::where('code', RoleCode::Customer->value)->firstOrFail());
            return $user;
        });
    }
}

