<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Snapshot inmutable de entrada de una PlanningRequest. Se crea únicamente
 * desde ConfirmPlanningRequest. Actualizar/borrar filas está bloqueado por
 * trigger `request_input_versions_immutability_trg`; a nivel modelo también
 * refusamos UPDATE/DELETE para no perder tiempo tocando la base.
 */
class RequestInputVersion extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'revision',
        'snapshot',
        'manifest',
        'created_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'manifest' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * Reject any update after the row exists: snapshots son inmutables por
     * definición. La base también lo protege con trigger.
     */
    protected static function booted(): void
    {
        static::updating(function (): bool {
            throw new \RuntimeException('REQUEST_INPUT_VERSION_IMMUTABLE');
        });
        static::deleting(function (): bool {
            throw new \RuntimeException('REQUEST_INPUT_VERSION_IMMUTABLE');
        });
    }
}
