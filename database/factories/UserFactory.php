<?php
namespace Database\Factories;
use App\Enums\RoleCode;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
class UserFactory extends Factory {
    protected static ?string $password;
    public function definition(): array {
        return ['name'=>fake()->name(), 'email'=>fake()->unique()->userName().'@example.test', 'email_verified_at'=>now(), 'password'=>static::$password ??= Hash::make('PruebaSegura123!'), 'status'=>'active'];
    }
    public function unverified(): static { return $this->state(fn()=>['email_verified_at'=>null]); }
    public function withRole(RoleCode $role): static {
        return $this->afterCreating(fn(User $user)=>$user->roles()->attach(Role::where('code',$role->value)->firstOrFail()));
    }
}
