<?php

namespace App\Models;

use App\Enums\CorrectionRequestStatus;
use App\Enums\CorrectionRequestType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class CorrectionRequest extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'request_id',
        'delivered_version_id',
        'source_version_id',
        'requester_id',
        'type',
        'reason',
        'description',
        'section_keys',
        'status',
        'assigned_to',
        'requested_at',
        'resolved_at',
        'resolution',
        'resulting_version_id',
    ];

    protected function casts(): array
    {
        return [
            'type' => CorrectionRequestType::class,
            'status' => CorrectionRequestStatus::class,
            'section_keys' => 'array',
            'requested_at' => 'datetime',
            'resolved_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function deliveredVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'delivered_version_id');
    }

    public function sourceVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'source_version_id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requester_id');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function resultingVersion(): BelongsTo
    {
        return $this->belongsTo(DocumentVersion::class, 'resulting_version_id');
    }

    public function reservation(): HasOne
    {
        return $this->hasOne(UsageReservation::class, 'correction_request_id');
    }
}
