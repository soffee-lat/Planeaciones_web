<?php
namespace App\Models;
use App\Enums\RoleCode;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
class User extends Authenticatable implements FilamentUser, MustVerifyEmail
{
    use HasFactory, Notifiable;
    protected $attributes = ['status' => 'active'];
    protected $fillable = ['name', 'email', 'password'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array {
        return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'onboarding_completed_at' => 'datetime'];
    }
    protected function email(): \Illuminate\Database\Eloquent\Casts\Attribute {
        return \Illuminate\Database\Eloquent\Casts\Attribute::make(set: fn (string $value) => mb_strtolower(trim($value)));
    }
    public function roles(): BelongsToMany { return $this->belongsToMany(Role::class); }
    public function hasRole(RoleCode $role): bool {
        return $this->roles()->where('code', $role->value)->exists();
    }
    public function schools(): \Illuminate\Database\Eloquent\Relations\HasMany {
        return $this->hasMany(School::class, 'owner_id');
    }
    public function groups(): \Illuminate\Database\Eloquent\Relations\HasMany {
        return $this->hasMany(Group::class, 'owner_id');
    }
    public function hasPedagogicalOnboardingComplete(): bool {
        return \App\Services\Onboarding\OnboardingProgress::isPedagogicalComplete($this);
    }
    public function canAccessPanel(Panel $panel): bool {
        if ($this->status !== 'active') { return false; }
        return match ($panel->getId()) {
            'app' => $this->hasRole(RoleCode::Customer),
            'review' => $this->hasRole(RoleCode::Reviewer) || $this->hasRole(RoleCode::Administrator),
            'admin' => $this->hasRole(RoleCode::Administrator),
            default => false,
        };
    }
}


