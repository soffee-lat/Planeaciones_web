<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class PlanningRequestSegment extends Model
{
    /** @use HasFactory<\Database\Factories\PlanningRequestSegmentFactory> */
    use HasFactory;

    protected $fillable = ['planning_request_id', 'sequence', 'starts_on', 'ends_on', 'calendar_days', 'units'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'sequence' => 'integer', 'calendar_days' => 'integer', 'units' => 'integer'];
    }

    public function planningRequest(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class);
    }
}
