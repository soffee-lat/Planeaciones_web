<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PlanningRequestWeekTopic extends Model
{
    use HasFactory;

    protected $fillable = [
        'planning_request_week_id',
        'group_subject_id',
        'topic',
        'notes',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
        ];
    }

    public function week(): BelongsTo
    {
        return $this->belongsTo(PlanningRequestWeek::class, 'planning_request_week_id');
    }

    public function subject(): BelongsTo
    {
        return $this->belongsTo(GroupSubject::class, 'group_subject_id');
    }
}
