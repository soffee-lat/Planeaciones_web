<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AiManualPackage extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'ai_execution_id',
        'disk',
        'path',
        'checksum',
        'size_bytes',
    ];

    protected function casts(): array
    {
        return [
            'size_bytes' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('AI_MANUAL_PACKAGE_IMMUTABLE');
        });
        static::deleting(function (): void {
            throw new RuntimeException('AI_MANUAL_PACKAGE_IMMUTABLE');
        });
    }

    public function execution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class, 'ai_execution_id');
    }
}
