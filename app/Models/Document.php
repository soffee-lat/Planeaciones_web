<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class Document extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'owner_id',
        'title',
        'current_version_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach (['request_id', 'owner_id'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('DOCUMENT_IDENTITY_IMMUTABLE');
                }
            }
        });

        static::deleting(function (): void {
            throw new RuntimeException('DOCUMENT_HISTORY_IMMUTABLE');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(DocumentVersion::class, 'document_id')->orderBy('number');
    }

    public function currentVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'current_version_id');
    }
}
