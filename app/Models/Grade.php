<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Grade extends Model
{
    use HasFactory;

    protected $fillable = ['curriculum_version_id', 'educational_phase_id', 'code', 'name', 'ordinal', 'sort_order'];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function educationalPhase(): BelongsTo
    {
        return $this->belongsTo(EducationalPhase::class);
    }

    public function pdas(): HasMany
    {
        return $this->hasMany(Pda::class);
    }
}
