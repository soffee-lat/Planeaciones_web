<?php

namespace App\Models;

use App\Enums\DocumentVersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class DocumentVersion extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'document_id',
        'number',
        'parent_version_id',
        'input_revision',
        'content',
        'content_hash',
        'source_payload_hash',
        'created_by',
        'ai_execution_id',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'content' => 'array',
            'status' => DocumentVersionStatus::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('DOCUMENT_VERSION_IMMUTABLE');
        });
        static::deleting(function (): void {
            throw new RuntimeException('DOCUMENT_VERSION_IMMUTABLE');
        });
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(Document::class, 'document_id');
    }

    public function parentVersion(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_version_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class, 'ai_execution_id');
    }

    public function outputFiles(): HasMany
    {
        return $this->hasMany(DocumentVersionFile::class, 'version_id');
    }
}
