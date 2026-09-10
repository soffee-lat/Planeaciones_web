<?php

namespace App\Models;

use App\Enums\ReviewerWorkItemStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewerWorkItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id', 'review_id', 'assignment_id', 'reviewer_id', 'cycle', 'rate_minor',
        'quantity', 'total_minor', 'currency', 'status', 'approved_at', 'paid_at', 'settlement_id',
    ];

    protected function casts(): array
    {
        return [
            'cycle' => 'integer',
            'rate_minor' => 'integer',
            'quantity' => 'integer',
            'total_minor' => 'integer',
            'status' => ReviewerWorkItemStatus::class,
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(HumanReview::class, 'review_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReviewerAssignment::class, 'assignment_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function settlement(): BelongsTo
    {
        return $this->belongsTo(ReviewerSettlement::class, 'settlement_id');
    }
}
