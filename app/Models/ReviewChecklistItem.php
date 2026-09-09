<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ReviewChecklistItem extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'checklist_version_id', 'key', 'label', 'description', 'required', 'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'required' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function checklistVersion(): BelongsTo
    {
        return $this->belongsTo(ReviewChecklistVersion::class, 'checklist_version_id');
    }

    public function responses(): HasMany
    {
        return $this->hasMany(ReviewChecklistResponse::class, 'checklist_item_id');
    }
}
