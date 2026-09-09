<?php

namespace App\Models;

use App\Enums\PromptCategory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use RuntimeException;

class PromptTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'category',
        'name',
        'active_version_id',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if (($model->isDirty('key') || $model->isDirty('category')) && $model->versions()->exists()) {
                throw new RuntimeException('PROMPT_TEMPLATE_IDENTITY_IMMUTABLE');
            }
        });
    }

    protected function casts(): array
    {
        return [
            'category' => PromptCategory::class,
        ];
    }

    public function versions(): HasMany
    {
        return $this->hasMany(PromptVersion::class, 'template_id');
    }

    public function activeVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'active_version_id');
    }
}
