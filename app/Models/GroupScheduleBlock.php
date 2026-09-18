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
        'group_subject_id',
        'subject_name_snapshot',
        'subject_color_snapshot',
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

    public function subject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class, 'group_subject_id');
    }
}
