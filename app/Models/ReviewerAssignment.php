<?php

namespace App\Models;

use App\Enums\ReviewAssignmentStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ReviewerAssignment extends Model
{
    use HasFactory;

    protected $table = 'review_assignments';

    protected $fillable = [
        'request_id', 'reviewer_id', 'cycle', 'status', 'due_at', 'assigned_at',
        'started_at', 'ended_at', 'ended_by', 'ended_reason', 'rate_snapshot_minor',
        'units_snapshot', 'total_fee_minor', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => 'integer',
            'status' => ReviewAssignmentStatus::class,
            'due_at' => 'datetime',
            'assigned_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'rate_snapshot_minor' => 'integer',
            'units_snapshot' => 'integer',
            'total_fee_minor' => 'integer',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ReviewerProfile::class, 'reviewer_id', 'user_id');
    }

    public function endedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'ended_by');
    }

    public function review(): HasOne
    {
        return $this->hasOne(HumanReview::class, 'assignment_id');
    }

    public function workItem(): HasOne
    {
        return $this->hasOne(ReviewerWorkItem::class, 'assignment_id');
    }
}
