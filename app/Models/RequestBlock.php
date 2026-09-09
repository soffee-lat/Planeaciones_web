<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class RequestBlock extends Model
{
    protected $fillable = [
        'request_id',
        'code',
        'stage',
        'details',
        'opened_at',
        'resolved_at',
        'resolved_by',
        'correlation_id',
    ];

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'opened_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['request_id', 'code', 'stage', 'details', 'opened_at', 'correlation_id'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('REQUEST_BLOCK_IDENTITY_IMMUTABLE');
                }
            }

            if ($model->getOriginal('resolved_at') !== null) {
                throw new RuntimeException('REQUEST_BLOCK_ALREADY_RESOLVED');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('REQUEST_BLOCK_HISTORY_IMMUTABLE');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    public function isOpen(): bool
    {
        return $this->resolved_at === null;
    }
}
