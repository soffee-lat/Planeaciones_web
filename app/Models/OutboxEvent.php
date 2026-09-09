<?php

namespace App\Models;

use App\Enums\OutboxEventType;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

class OutboxEvent extends Model
{
    protected $fillable = [
        'event_key',
        'type',
        'aggregate_id',
        'payload',
        'published_at',
        'attempts',
        'available_at',
        'claimed_at',
        'lease_expires_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'type' => OutboxEventType::class,
            'aggregate_id' => 'integer',
            'payload' => 'array',
            'attempts' => 'integer',
            'published_at' => 'datetime',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'lease_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['event_key', 'type', 'aggregate_id', 'payload', 'created_at'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('OUTBOX_EVENT_IDENTITY_IMMUTABLE');
                }
            }
            if ($model->getOriginal('published_at') !== null) {
                throw new RuntimeException('OUTBOX_EVENT_PUBLISHED_IMMUTABLE');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('OUTBOX_EVENT_HISTORY_IMMUTABLE');
        });
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
