<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ArticulatingAxis extends Model
{
    use HasFactory;

    protected $table = 'articulating_axes';

    protected $fillable = ['curriculum_version_id', 'code', 'name', 'description', 'sort_order'];

    public function curriculumVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class);
    }
}
