<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GroupScheduleBlock extends Model
{
    protected $fillable = [
        'group_schedule_id',
        'day_of_week',
        'sequence',
        'starts_at',
        'ends_at',
        'label',
        'block_type',
        'responsibility',
        'include_in_planning',
        'is_flexible',
        'field_codes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'sequence' => 'integer',
            'include_in_planning' => 'boolean',
            'is_flexible' => 'boolean',
            'field_codes' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(GroupSchedule::class, 'group_schedule_id');
    }
}
