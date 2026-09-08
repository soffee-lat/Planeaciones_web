<?php

namespace App\Models;

use App\Enums\SchoolType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class School extends Model
{
    use HasFactory;

    protected $fillable = [
        'owner_id',
        'name',
        'school_type',
        'state',
        'municipality',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'school_type' => SchoolType::class,
        ];
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function groups(): HasMany
    {
        return $this->hasMany(Group::class);
    }
}
