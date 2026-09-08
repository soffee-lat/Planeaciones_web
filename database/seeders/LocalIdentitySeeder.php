<?php
namespace Database\Seeders;
use App\Enums\RoleCode;
use App\Models\User;
use Illuminate\Database\Seeder;
class LocalIdentitySeeder extends Seeder {
    public function run(): void {
        if (! app()->environment('local', 'testing')) { throw new \LogicException('Solo datos ficticios locales.'); }
        $password = bin2hex(random_bytes(16));
        foreach (['cliente' => RoleCode::Customer, 'revisora' => RoleCode::Reviewer, 'admin' => RoleCode::Administrator] as $label => $role) {
            if (User::where('email', $label.'@example.test')->exists()) { continue; }
            User::factory()->withRole($role)->create(['name' => 'Demo '.ucfirst($label), 'email' => $label.'@example.test', 'password' => $password]);
        }
        file_put_contents(storage_path('app/private/local-demo-password.txt'), $password);
        $this->command?->info('Cuentas ficticias creadas. Contraseña local en storage/app/private/local-demo-password.txt.');
    }
}

