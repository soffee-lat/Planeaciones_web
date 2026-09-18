<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupSchedule extends Model
{
    protected $fillable = [
        'group_id',
        'revision',
        'name',
        'active_days',
        'exceptions',
        'day_starts_at',
        'day_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'active_days' => 'array',
            'exceptions' => 'array',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(GroupScheduleBlock::class)
            ->orderBy('day_of_week')
            ->orderBy('starts_at')
            ->orderBy('sequence');
    }

    public function isUsable(): bool
    {
        return is_array($this->active_days)
            && $this->active_days !== []
            && (string) $this->day_starts_at < (string) $this->day_ends_at;
    }
}
