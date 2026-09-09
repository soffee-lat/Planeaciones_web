<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class PromptVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_id',
        'number',
        'body',
        'allowed_variables',
        'output_schema',
        'schema_version',
        'published_at',
        'created_by',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'allowed_variables' => 'array',
            'output_schema' => 'array',
            'published_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if ($model->getOriginal('published_at') !== null) {
                throw new RuntimeException('PROMPT_VERSION_PUBLISHED_IMMUTABLE');
            }
        });

        static::deleting(function (self $model) {
            if ($model->published_at !== null) {
                throw new RuntimeException('PROMPT_VERSION_PUBLISHED_IMMUTABLE');
            }
        });
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(PromptTemplate::class, 'template_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function executions(): HasMany
    {
        return $this->hasMany(AiExecution::class, 'prompt_version_id');
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }
}
