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
        'day_starts_at',
        'day_ends_at',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
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
        return $this->blocks()
            ->where('include_in_planning', true)
            ->whereNotIn('block_type', ['break', 'external'])
            ->exists();
    }
}
