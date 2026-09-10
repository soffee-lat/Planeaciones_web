<?php

namespace App\Models;

use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InstitutionalFormat extends Model
{
    use HasFactory;

    protected $fillable = ['owner_id', 'name', 'kind', 'status'];

    protected function casts(): array
    {
        return [
            'kind' => InstitutionalFormatKind::class,
            'status' => InstitutionalFormatStatus::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function versions(): HasMany
    {
        return $this->hasMany(FormatVersion::class, 'format_id')->orderBy('number');
    }

    public function publishedVersions(): HasMany
    {
        return $this->hasMany(FormatVersion::class, 'format_id')
            ->whereNotNull('published_at')
            ->orderByDesc('number');
    }
}
