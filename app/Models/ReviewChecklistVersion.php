<?php

namespace App\Models;

use App\Enums\ReviewChecklistVersionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewChecklistVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'key', 'version', 'name', 'status', 'published_at', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'version' => 'integer',
            'status' => ReviewChecklistVersionStatus::class,
            'published_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ReviewChecklistItem::class, 'checklist_version_id')->orderBy('sort_order')->orderBy('id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
