<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PlanningRequestWeek extends Model
{
    use HasFactory;

    protected $fillable = [
        'planning_request_id',
        'sequence',
        'starts_on',
        'ends_on',
        'label',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
        ];
    }

    public function planningRequest(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class);
    }

    public function topics(): HasMany
    {
        return $this->hasMany(PlanningRequestWeekTopic::class)->orderBy('sort_order')->orderBy('id');
    }
}
