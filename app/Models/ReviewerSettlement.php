<?php

namespace App\Models;

use App\Enums\ReviewerSettlementStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewerSettlement extends Model
{
    use HasFactory;

    protected $fillable = [
        'reviewer_id', 'currency', 'status', 'reference', 'approved_by', 'approved_at',
        'paid_by', 'paid_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => ReviewerSettlementStatus::class,
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewer_id');
    }

    public function approvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'paid_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReviewerWorkItem::class, 'settlement_id');
    }

    public function totalMinor(): int
    {
        return (int) $this->items()->sum('total_minor');
    }
}
