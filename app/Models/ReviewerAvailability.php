<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewerAvailability extends Model
{
    use HasFactory;

    protected $table = 'reviewer_availability';

    protected $fillable = ['reviewer_id', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return ['starts_at' => 'datetime', 'ends_at' => 'datetime'];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function profile(): BelongsTo
    {
        return $this->belongsTo(ReviewerProfile::class, 'reviewer_id', 'user_id');
    }
}
