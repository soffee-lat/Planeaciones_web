<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class PlanningDelivery extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'deliveries';

    protected $fillable = [
        'request_id', 'version_id', 'render_run_id', 'delivered_at', 'created_by', 'idempotency_key', 'correlation_id',
    ];

    protected function casts(): array
    {
        return ['delivered_at' => 'datetime', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new RuntimeException('DELIVERY_HISTORY_IMMUTABLE'));
        static::deleting(fn () => throw new RuntimeException('DELIVERY_HISTORY_IMMUTABLE'));
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function renderRun(): BelongsTo
    {
        return $this->belongsTo(DocumentRenderRun::class, 'render_run_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function files(): BelongsToMany
    {
        return $this->belongsToMany(StoredFile::class, 'delivery_files', 'delivery_id', 'file_id')
            ->withPivot(['output_format', 'renderer_version', 'created_at']);
    }

    public function downloads(): HasMany
    {
        return $this->hasMany(DeliveryDownload::class, 'delivery_id');
    }
}
