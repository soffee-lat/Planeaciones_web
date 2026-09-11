<?php

namespace App\Models;

use App\Enums\ProductEventType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductEvent extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'planning_request_id',
        'group_id',
        'curriculum_version_id',
        'grade_id',
        'event_type',
        'metadata',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'event_type' => ProductEventType::class,
            'metadata' => 'array',
            'occurred_at' => 'immutable_datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function planningRequest(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }
}
