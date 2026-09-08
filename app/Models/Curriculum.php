<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Curriculum extends Model
{
    use HasFactory;

    protected $fillable = ['code', 'name', 'country_code', 'educational_level', 'description', 'selectable_version_id'];

    public function versions(): HasMany
    {
        return $this->hasMany(CurriculumVersion::class);
    }

    public function selectableVersion(): BelongsTo
    {
        return $this->belongsTo(CurriculumVersion::class, 'selectable_version_id');
    }
}
