<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class PlanVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'plan_id',
        'number',
        'price_minor',
        'currency',
        'interval_unit',
        'interval_count',
        'max_planning_days',
        'planning_limit',
        'human_review_limit',
        'correction_limit',
        'group_limit',
        'correction_window_days',
        'sla_hours',
        'human_review_required',
        'features',
        'effective_from',
        'effective_until',
        'published_at',
        'published_by',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'features' => 'array',
            'human_review_required' => 'boolean',
            'effective_from' => 'date',
            'effective_until' => 'date',
            'published_at' => 'datetime',
        ];
    }

    /**
     * Defensa en profundidad a nivel modelo: aunque el trigger PostgreSQL
     * ya rechaza UPDATE/DELETE de versiones publicadas, Eloquent falla temprano
     * con un código de dominio semántico y evita transacciones inútiles.
     */
    protected static function booted(): void
    {
        static::updating(function (self $model) {
            if ($model->getOriginal('published_at') !== null) {
                throw new RuntimeException('PLAN_VERSION_PUBLISHED_IMMUTABLE');
            }
        });
        static::deleting(function (self $model) {
            if ($model->published_at !== null) {
                throw new RuntimeException('PLAN_VERSION_PUBLISHED_IMMUTABLE');
            }
        });
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
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
