<?php

namespace App\Models;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

class AiExecution extends Model
{
    use HasFactory;

    protected $fillable = [
        'request_id',
        'format_version_id',
        'stage',
        'mode',
        'provider',
        'model',
        'prompt_version_id',
        'input_revision',
        'input_manifest',
        'rendered_prompt_hash',
        'private_payload_file_id',
        'operation_key',
        'status',
        'started_at',
        'finished_at',
        'duration_ms',
        'error_code',
        'sanitized_error',
        'estimated_cost',
        'actual_cost',
        'cost_currency',
        'resulting_version_id',
        'audit_report',
    ];

    protected static function booted(): void
    {
        static::updating(function (self $model) {
            foreach (['request_id', 'format_version_id', 'stage', 'mode', 'prompt_version_id', 'input_revision', 'input_manifest', 'operation_key'] as $field) {
                if ($model->isDirty($field)) {
                    throw new RuntimeException('AI_EXECUTION_IDENTITY_IMMUTABLE');
                }
            }
        });

        static::deleting(function () {
            throw new RuntimeException('AI_EXECUTION_HISTORY_IMMUTABLE');
        });
    }

    protected function casts(): array
    {
        return [
            'stage' => AiExecutionStage::class,
            'mode' => AiExecutionMode::class,
            'status' => AiExecutionStatus::class,
            'input_manifest' => 'array',
            'audit_report' => 'array',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'estimated_cost' => 'decimal:8',
            'actual_cost' => 'decimal:8',
        ];
    }

    public function request(): BelongsTo
    {
        return $this->belongsTo(PlanningRequest::class, 'request_id');
    }

    public function promptVersion(): BelongsTo
    {
        return $this->belongsTo(PromptVersion::class, 'prompt_version_id');
    }
}
