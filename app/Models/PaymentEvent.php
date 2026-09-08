<?php

namespace App\Models;

use App\Enums\PaymentEventStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PaymentEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'provider',
        'event_id',
        'payload_hash',
        'status',
        'received_at',
        'processed_at',
        'error_code',
        'payment_id',
        'order_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => PaymentEventStatus::class,
            'received_at' => 'datetime',
            'processed_at' => 'datetime',
        ];
    }

    public function payment(): BelongsTo
    {
        return $this->belongsTo(Payment::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }
}
