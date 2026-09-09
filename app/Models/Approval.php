<?php

namespace App\Models;

use App\Enums\ApprovalKind;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class Approval extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'version_id',
        'kind',
        'ai_execution_id',
        'review_id',
        'actor_id',
        'approved_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => ApprovalKind::class,
            'approved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (): void {
            throw new RuntimeException('APPROVAL_IMMUTABLE');
        });
        static::deleting(function (): void {
            throw new RuntimeException('APPROVAL_IMMUTABLE');
        });
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'version_id');
    }

    public function aiExecution(): BelongsTo
    {
        return $this->belongsTo(AiExecution::class, 'ai_execution_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_id');
    }
}
