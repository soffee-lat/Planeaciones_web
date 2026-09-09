<?php

namespace App\Models;

use App\Enums\HumanReviewStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class HumanReview extends Model
{
    use HasFactory;

    public const SECTION_KEYS = [
        'planning',
        'context',
        'curricular_alignment',
        'pedagogical_design',
        'sessions',
        'assessment_plan',
        'resources',
        'adaptation_notes',
    ];

    protected $table = 'reviews';

    protected $fillable = [
        'request_id', 'assignment_id', 'version_id', 'checklist_version_id', 'reviewer_id',
        'status', 'started_at', 'decided_at', 'general_comment', 'section_comments',
    ];

    protected function casts(): array
    {
        return [
            'status' => HumanReviewStatus::class,
            'started_at' => 'datetime',
            'decided_at' => 'datetime',
            'section_comments' => 'array',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function assignment(): BelongsTo
    {
        return $this->belongsTo(ReviewerAssignment::class, 'assignment_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ReviewChecklistVersion::class, 'checklist_version_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReviewChecklistResponse::class, 'review_id');
    }
}
