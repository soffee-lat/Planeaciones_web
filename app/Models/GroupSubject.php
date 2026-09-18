<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GroupSubject extends Model
{
    protected $fillable = [
        'group_id',
        'name',
        'slug',
        'color',
        'origin',
        'curriculum_field_code',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function scheduleBlocks(): HasMany
    {
        return $this->hasMany(GroupScheduleBlock::class);
    }
}
