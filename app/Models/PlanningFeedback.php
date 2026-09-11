<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class PlanningFeedback extends Model
{
    public $timestamps = false;

    protected $table = 'planning_feedback';

    protected $fillable = [
        'planning_request_id',
        'user_id',
        'delivery_id',
        'saved_time_bucket',
        'most_helpful',
        'next_real_planning',
        'submitted_at',
    ];

    protected function casts(): array
    {
        return ['submitted_at' => 'immutable_datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('PLANNING_FEEDBACK_IMMUTABLE'));
        static::deleting(fn () => throw new RuntimeException('PLANNING_FEEDBACK_IMMUTABLE'));
    }

    public function planningRequest(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'planning_request_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PlanningDelivery::class, 'delivery_id');
    }
}
