<?php

namespace Database\Seeders;

use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class LocalIdentitySeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \LogicException('Solo datos ficticios locales.');
        }

        $password = (string) config('local_demo.password', 'PruebaLocal2026!');
        if (mb_strlen($password) < 12) {
            throw new \LogicException('LOCAL_DEMO_PASSWORD debe tener al menos 12 caracteres.');
        }

        $accounts = [
            'cliente@example.test' => ['name' => 'Demo Cliente', 'role' => RoleCode::Customer],
            'revisora@example.test' => ['name' => 'Demo Revisora', 'role' => RoleCode::Reviewer],
            'admin@example.test' => ['name' => 'Demo Admin', 'role' => RoleCode::Administrator],
        ];

        foreach ($accounts as $email => $definition) {
            $user = User::query()->firstOrNew(['email' => $email]);
            $user->forceFill([
                'name' => $definition['name'],
                'email' => $email,
                'password' => $password,
                'status' => 'active',
                'email_verified_at' => now(),
            ])->save();

            $role = Role::query()->where('code', $definition['role']->value)->firstOrFail();
            // Estas direcciones están reservadas para QA: cada una conserva un rol exacto.
            $user->roles()->sync([$role->id]);
        }

        Storage::disk('private')->put('local-demo-password.txt', $password);

        $this->command?->info('Cuentas demo locales listas: admin@example.test, cliente@example.test y revisora@example.test.');
        $this->command?->info('Contraseña: LOCAL_DEMO_PASSWORD (por defecto PruebaLocal2026!) y copia en storage/app/private/local-demo-password.txt.');
    }
}
