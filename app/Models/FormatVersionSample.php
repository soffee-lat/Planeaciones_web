<?php

namespace App\Models;

use App\Enums\FormatSampleStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class FormatVersionSample extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'format_version_id', 'source_file_id', 'mapping_snapshot', 'renderer_version',
        'fingerprint', 'docx_file_id', 'pdf_file_id', 'status', 'created_by',
        'reviewed_by', 'review_note', 'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'mapping_snapshot' => 'array',
            'status' => FormatSampleStatus::class,
            'created_at' => 'datetime',
            'reviewed_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model): void {
            foreach ([
                'format_version_id', 'source_file_id', 'mapping_snapshot', 'renderer_version',
                'fingerprint', 'docx_file_id', 'pdf_file_id', 'created_by', 'created_at',
            ] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('FORMAT_SAMPLE_IDENTITY_IMMUTABLE');
                }
            }
            $before = $model->getOriginal('status');
            if ($before !== FormatSampleStatus::Pending->value && $model->isDirty()) {
                throw new RuntimeException('FORMAT_SAMPLE_TERMINAL_IMMUTABLE');
            }
        });
        static::deleting(fn () => throw new RuntimeException('FORMAT_SAMPLE_HISTORY_IMMUTABLE'));
    }

    public function formatVersion(): BelongsTo
    {
        return $this->belongsTo(FormatVersion::class, 'format_version_id');
    }

    public function sourceFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'source_file_id');
    }

    public function docxFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'docx_file_id');
    }

    public function pdfFile(): BelongsTo
    {
        return $this->belongsTo(StoredFile::class, 'pdf_file_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
