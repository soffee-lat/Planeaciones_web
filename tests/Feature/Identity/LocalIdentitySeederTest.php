<?php

namespace Tests\Feature\Identity;

use App\Enums\RoleCode;
use App\Models\User;
use Database\Seeders\LocalIdentitySeeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\PedagogyTestCase;

class LocalIdentitySeederTest extends PedagogyTestCase
{
    public function test_cuentas_demo_son_predecibles_e_idempotentes(): void
    {
        Storage::fake('private');
        config()->set('local_demo.password', 'PruebaLocal2026!');

        $this->seed(LocalIdentitySeeder::class);
        $this->seed(LocalIdentitySeeder::class);

        $expected = [
            'cliente@example.test' => RoleCode::Customer,
            'revisora@example.test' => RoleCode::Reviewer,
            'admin@example.test' => RoleCode::Administrator,
        ];

        $this->assertSame(3, User::query()->whereIn('email', array_keys($expected))->count());

        foreach ($expected as $email => $role) {
            $user = User::query()->where('email', $email)->sole();
            $this->assertSame('active', $user->status);
            $this->assertNotNull($user->email_verified_at);
            $this->assertTrue(Hash::check('PruebaLocal2026!', $user->password));
            $this->assertSame([$role->value], $user->roles()->pluck('code')->all());
        }

        $this->assertSame('PruebaLocal2026!', Storage::disk('private')->get('local-demo-password.txt'));
    }
}
