<?php

namespace App\Models;

use App\Enums\DocumentRenderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class DocumentRenderRun extends Model
{
    protected $fillable = [
        'request_id', 'version_id', 'format_version_id', 'operation_key', 'correlation_id',
        'renderer_version', 'status', 'attempts', 'manifest', 'started_at', 'finished_at',
        'last_error_code',
    ];

    protected function casts(): array
    {
        return [
            'status' => DocumentRenderStatus::class,
            'attempts' => 'integer',
            'manifest' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['request_id', 'version_id', 'format_version_id', 'operation_key', 'correlation_id', 'renderer_version', 'created_at'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('DOCUMENT_RENDER_IDENTITY_IMMUTABLE');
                }
            }

            if ($model->getOriginal('status') === DocumentRenderStatus::Succeeded->value && $model->isDirty()) {
                throw new RuntimeException('DOCUMENT_RENDER_SUCCEEDED_IMMUTABLE');
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('DOCUMENT_RENDER_HISTORY_IMMUTABLE');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function formatVersion(): BelongsTo
    {
        return $this->belongsTo(FormatVersion::class, 'format_version_id');
    }
}
