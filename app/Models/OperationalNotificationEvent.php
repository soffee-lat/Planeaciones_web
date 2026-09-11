<?php

namespace App\Models;

use App\Enums\OperationalNotificationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class OperationalNotificationEvent extends Model
{
    protected $fillable = [
        'event_key', 'type', 'recipient_id', 'aggregate_type', 'aggregate_id', 'payload',
        'internal_sent_at', 'email_sent_at', 'email_attempts', 'email_available_at',
        'email_claimed_at', 'email_lease_expires_at', 'email_last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'type' => OperationalNotificationType::class,
            'aggregate_id' => 'integer',
            'payload' => 'array',
            'internal_sent_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'email_attempts' => 'integer',
            'email_available_at' => 'datetime',
            'email_claimed_at' => 'datetime',
            'email_lease_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['event_key', 'type', 'recipient_id', 'aggregate_type', 'aggregate_id', 'payload', 'created_at'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('OPERATIONAL_NOTIFICATION_IDENTITY_IMMUTABLE');
                }
            }
            if ($model->getOriginal('internal_sent_at') !== null && $model->isDirty('internal_sent_at')) {
                throw new RuntimeException('OPERATIONAL_NOTIFICATION_INTERNAL_TERMINAL');
            }
            if ($model->getOriginal('email_sent_at') !== null && $model->isDirty('email_sent_at')) {
                throw new RuntimeException('OPERATIONAL_NOTIFICATION_EMAIL_TERMINAL');
            }
        });

        static::deleting(fn () => throw new RuntimeException('OPERATIONAL_NOTIFICATION_HISTORY_IMMUTABLE'));
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recipient_id');
    }
}
