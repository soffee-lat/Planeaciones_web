<?php

namespace App\Models;

use App\Enums\FileCategory;
use App\Enums\FileScanStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class StoredFile extends Model
{
    use HasFactory;

    protected $table = 'files';

    public const UPDATED_AT = null;

    protected $fillable = [
        'owner_id', 'request_id', 'category', 'disk', 'path', 'original_name',
        'detected_mime', 'size_bytes', 'sha256', 'scan_status', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'category' => FileCategory::class,
            'scan_status' => FileScanStatus::class,
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['owner_id', 'request_id', 'category', 'disk', 'path', 'original_name', 'detected_mime', 'size_bytes', 'sha256', 'uploaded_by'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('FILE_IDENTITY_IMMUTABLE');
                }
            }

            $before = $model->getOriginal('scan_status');
            if ($before !== FileScanStatus::Pending->value && $model->isDirty('scan_status')) {
                throw new RuntimeException('FILE_SCAN_STATUS_TERMINAL');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('FILE_HISTORY_IMMUTABLE');
        });
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}
