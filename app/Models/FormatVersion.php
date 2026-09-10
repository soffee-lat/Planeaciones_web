<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class FormatVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'format_id', 'number', 'source_file_id', 'mapping', 'schema_version',
        'renderer', 'validation_report', 'approved_by', 'published_at',
    ];

    protected function casts(): array
    {
        return [
            'number' => 'integer',
            'schema_version' => 'integer',
            'mapping' => 'array',
            'validation_report' => 'array',
            'published_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            if ($model->getOriginal('published_at') !== null) {
                throw new RuntimeException('FORMAT_VERSION_PUBLISHED_IMMUTABLE');
            }
        });

        static::deleting(function (self $model): void {
            if ($model->published_at !== null) {
                throw new RuntimeException('FORMAT_VERSION_PUBLISHED_IMMUTABLE');
            }
        });
    }

    public function format(): BelongsTo
    {
        return $this->belongsTo(InstitutionalFormat::class, 'format_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'source_file_id');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function renderRuns(): HasMany
    {
        return $this->hasMany(DocumentRenderRun::class, 'format_version_id');
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
