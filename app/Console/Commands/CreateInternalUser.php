<?php
namespace App\Console\Commands;
use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;
class CreateInternalUser extends Command {
    protected $signature = 'identity:create-internal {email} {--role=DOCENTE_REVISOR}';
    protected $description = 'Crea una cuenta interna sin publicar credenciales ni permitir registro privilegiado.';
    public function handle(): int {
        $role = RoleCode::tryFrom($this->option('role'));
        if (! in_array($role, [RoleCode::Reviewer, RoleCode::Administrator], true)) {
            $this->error('Rol interno inválido.'); return self::FAILURE;
        }
        $data = ['name' => $this->ask('Nombre'), 'email' => mb_strtolower(trim($this->argument('email'))), 'password' => $this->secret('Contraseña (mínimo 12 caracteres)')];
        $validator = Validator::make($data, ['name' => 'required|string|max:255', 'email' => 'required|email|max:255|unique:users,email', 'password' => ['required', Password::min(12)]]);
        if ($validator->fails()) { $this->error('Datos inválidos: revisa nombre, correo y longitud de contraseña.'); return self::FAILURE; }
        DB::transaction(function () use ($data, $role) {
            $user = User::create($data);
            $user->roles()->attach(Role::where('code', $role->value)->firstOrFail());
        });
        $this->info('Cuenta creada. Debe verificar su correo al iniciar sesión.');
        return self::SUCCESS;
    }
}

