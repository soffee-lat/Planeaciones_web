<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class DeliveryDownload extends Model
{
    public $timestamps = false;

    protected $table = 'delivery_downloads';

    protected $fillable = ['delivery_id', 'file_id', 'user_id', 'downloaded_at'];

    protected function casts(): array
    {
        return ['downloaded_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('DELIVERY_DOWNLOAD_IMMUTABLE'));
        static::deleting(fn () => throw new RuntimeException('DELIVERY_DOWNLOAD_IMMUTABLE'));
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(PlanningDelivery::class, 'delivery_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }
}
