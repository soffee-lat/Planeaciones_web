<?php

namespace App\Models;

use App\Enums\SubscriptionPeriodStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPeriod extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_id',
        'plan_id',
        'plan_version_id',
        'starts_at',
        'ends_at',
        'status',
        'entitlement_snapshot',
        'paid_order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => SubscriptionPeriodStatus::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'entitlement_snapshot' => 'array',
        ];
    }

    public function subscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(UsageReservation::class);
    }

    public function isReservable(): bool
    {
        $now = now();
        return $this->status === SubscriptionPeriodStatus::Active
            && $this->starts_at?->lte($now)
            && $this->ends_at?->gt($now);
    }

    public function entitlement(string $key): int
    {
        return (int) ($this->entitlement_snapshot[$key] ?? 0);
    }
}
