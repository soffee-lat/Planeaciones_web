<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestStateEvent extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'from_status',
        'to_status',
        'actor_id',
        'actor_type',
        'reason',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new \RuntimeException('REQUEST_STATE_EVENT_IMMUTABLE');
        });
        static::deleting(function (): void {
            throw new \RuntimeException('REQUEST_STATE_EVENT_IMMUTABLE');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
