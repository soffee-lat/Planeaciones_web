<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    use HasFactory;

    protected $fillable = [
        'customer_id',
        'plan_id',
        'plan_version_id',
        'subscription_period_id',
        'activated_subscription_id',
        'created_by',
        'concept',
        'total_minor',
        'currency',
        'status',
        'idempotency_key',
        'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'total_minor' => 'integer',
            'paid_at' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'customer_id');
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function planVersion(): BelongsTo
    {
        return $this->belongsTo(PlanVersion::class);
    }

    public function activatedSubscription(): BelongsTo
    {
        return $this->belongsTo(Subscription::class, 'activated_subscription_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function successfulPayment(): ?Payment
    {
        return $this->payments()->where('status', 'succeeded')->first();
    }

    public function isPaid(): bool
    {
        return $this->status === OrderStatus::Paid || $this->status === OrderStatus::Refunded;
    }
}
