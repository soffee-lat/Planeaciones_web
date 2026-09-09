<?php

namespace App\Models;

use App\Enums\ReviewerProfileStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewerProfile extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id', 'status', 'max_load', 'daily_max', 'rate_minor', 'currency',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewerProfileStatus::class,
            'max_load' => 'integer',
            'daily_max' => 'integer',
            'rate_minor' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function grades(): BelongsToMany
    {
        return $this->belongsToMany(Grade::class, 'reviewer_grades', 'reviewer_id', 'grade_id', 'user_id')
            ->withPivot('curriculum_version_id');
    }

    public function availability(): HasMany
    {
        return $this->hasMany(ReviewerAvailability::class, 'reviewer_id', 'user_id');
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(ReviewerAssignment::class, 'reviewer_id', 'user_id');
    }
}
