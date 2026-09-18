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
        'valid_from',
        'valid_until',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'revision' => 'integer',
            'valid_from' => 'date',
            'valid_until' => 'date',
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function blocks(): HasMany
    {
        return $this->hasMany(GroupScheduleBlock::class)->orderBy('day_of_week')->orderBy('sequence');
    }
}
