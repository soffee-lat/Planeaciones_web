<?php
namespace App\Providers;
use App\Models\User;
use App\Policies\UserPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
class AppServiceProvider extends ServiceProvider {
    public function register(): void {}
    public function boot(): void {
        if (config('database.default') !== 'pgsql') {
            throw new \LogicException('Esta aplicación solo admite PostgreSQL (pgsql).');
        }
        Gate::policy(User::class, UserPolicy::class);
        Password::defaults(fn () => Password::min(12));
    }
}

