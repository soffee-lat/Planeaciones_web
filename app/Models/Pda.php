<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Pda extends Model
{
    use HasFactory;

    protected $table = 'pdas';

    protected $fillable = [
        'curriculum_version_id',
        'curricular_content_id',
        'grade_id',
        'code',
        'full_text',
        'source_locator',
        'sort_order',
    ];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }

    public function curricularContent(): BelongsTo
    {
        return $this->belongsTo(CurricularContent::class);
    }

    public function grade(): BelongsTo
    {
        return $this->belongsTo(Grade::class);
    }
}
