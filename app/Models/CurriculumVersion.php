<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurriculumVersion extends Model
{
    use HasFactory;

    protected $fillable = [
        'curriculum_id',
        'number',
        'label',
        'source_reference',
        'effective_from',
        'effective_until',
        'published_at',
        'published_by',
        'checksum',
    ];

    protected function casts(): array
    {
        return [
            'effective_from' => 'date',
            'effective_until' => 'date',
            'published_at' => 'datetime',
        ];
    }

    public function curriculum(): BelongsTo
    {
        return $this->belongsTo(Curriculum::class);
    }

    public function publisher(): BelongsTo
    {
        return $this->belongsTo(User::class, 'published_by');
    }

    public function phases(): HasMany
    {
        return $this->hasMany(EducationalPhase::class);
    }

    public function grades(): HasMany
    {
        return $this->hasMany(Grade::class);
    }

    public function formativeFields(): HasMany
    {
        return $this->hasMany(FormativeField::class);
    }

    public function curricularContents(): HasMany
    {
        return $this->hasMany(CurricularContent::class);
    }

    public function pdas(): HasMany
    {
        return $this->hasMany(Pda::class);
    }

    public function articulatingAxes(): HasMany
    {
        return $this->hasMany(ArticulatingAxis::class);
    }

    public function isPublished(): bool
    {
        return $this->published_at !== null;
    }

    public function isDraft(): bool
    {
        return $this->published_at === null;
    }
}
