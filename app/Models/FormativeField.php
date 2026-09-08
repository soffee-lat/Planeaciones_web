<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class FormativeField extends Model
{
    use HasFactory;

    protected $fillable = ['curriculum_version_id', 'code', 'name', 'description', 'sort_order'];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function curricularContents(): HasMany
    {
        return $this->hasMany(CurricularContent::class);
    }
}
