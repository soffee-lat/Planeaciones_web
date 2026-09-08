<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CurricularContent extends Model
{
    use HasFactory;

    protected $table = 'curricular_contents';

    protected $fillable = [
        'curriculum_version_id',
        'educational_phase_id',
        'formative_field_id',
        'code',
        'title',
        'full_text',
        'source_locator',
        'sort_order',
    ];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function educationalPhase(): BelongsTo
    {
        return $this->belongsTo(EducationalPhase::class);
    }

    public function formativeField(): BelongsTo
    {
        return $this->belongsTo(FormativeField::class);
    }

    public function pdas(): HasMany
    {
        return $this->hasMany(Pda::class);
    }
}
