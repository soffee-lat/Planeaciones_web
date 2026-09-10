<?php

namespace App\Models;

use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UsageReservation extends Model
{
    use HasFactory;

    protected $fillable = [
        'subscription_period_id',
        'planning_request_id',
        'correction_request_id',
        'resource',
        'operation_key',
        'quantity',
        'status',
        'reserved_at',
        'consumed_at',
        'released_at',
    ];

    protected function casts(): array
    {
        return [
            'resource' => UsageResource::class,
            'status' => UsageReservationStatus::class,
            'quantity' => 'integer',
            'reserved_at' => 'datetime',
            'consumed_at' => 'datetime',
            'released_at' => 'datetime',
        ];
    }

    public function subscriptionPeriod(): BelongsTo
    {
        return $this->belongsTo(SubscriptionPeriod::class);
    }

    public function planningRequest(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class);
    }

    public function correctionRequest(): BelongsTo
    {
        return $this->belongsTo(CorrectionRequest::class, 'correction_request_id');
    }

    public function isReserved(): bool
    {
        return $this->status === UsageReservationStatus::Reserved;
    }

    public function isConsumed(): bool
    {
        return $this->status === UsageReservationStatus::Consumed;
    }

    public function isReleased(): bool
    {
        return $this->status === UsageReservationStatus::Released;
    }
}
