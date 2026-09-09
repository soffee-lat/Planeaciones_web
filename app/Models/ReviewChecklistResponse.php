<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReviewChecklistResponse extends Model
{
    use HasFactory;

    protected $fillable = [
        'review_id', 'checklist_item_id', 'passed', 'comment',
    ];

    protected function casts(): array
    {
        return ['passed' => 'boolean'];
    }

    public function review(): BelongsTo
    {
        return $this->belongsTo(HumanReview::class, 'review_id');
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(ReviewChecklistItem::class, 'checklist_item_id');
    }
}
