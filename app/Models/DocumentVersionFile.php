<?php

namespace App\Models;

use App\Enums\DocumentOutputFormat;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class DocumentVersionFile extends Model
{
    public $incrementing = false;
    public $timestamps = false;

    protected $table = 'document_version_files';

    protected $primaryKey = null;

    protected $fillable = ['version_id', 'file_id', 'output_format', 'renderer_version'];

    protected function casts(): array
    {
        return [
            'output_format' => DocumentOutputFormat::class,
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('DOCUMENT_VERSION_FILE_IMMUTABLE');
        });
        static::deleting(function (): void {
            throw new RuntimeException('DOCUMENT_VERSION_FILE_IMMUTABLE');
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function file(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'file_id');
    }
}
