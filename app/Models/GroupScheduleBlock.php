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
        'block_type',
        'label',
        'responsibility',
        'include_in_planning',
        'field_codes',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'day_of_week' => 'integer',
            'sequence' => 'integer',
            'include_in_planning' => 'boolean',
            'field_codes' => 'array',
        ];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(GroupSchedule::class, 'group_schedule_id');
    }

    public function durationMinutes(): int
    {
        $start = \Carbon\CarbonImmutable::createFromFormat('H:i:s', (string) $this->starts_at);
        $end = \Carbon\CarbonImmutable::createFromFormat('H:i:s', (string) $this->ends_at);

        return $start->diffInMinutes($end);
    }
}
