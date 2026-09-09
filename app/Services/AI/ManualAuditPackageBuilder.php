<?php

namespace App\Services\AI;

use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\AiManualPackage;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

final class ManualAuditPackageBuilder
{
    public function __construct(
        private AuditInputBuilder $inputBuilder,
        private AuditPromptPolicy $promptPolicy,
        private ManualAiConfiguration $manualConfiguration,
        private PromptRenderer $promptRenderer,
    ) {}

    public function build(AiExecution $execution): AiManualPackage
    {
        $this->manualConfiguration->assertReady();
        if ($execution->stage !== AiExecutionStage::Audit || $execution->mode !== AiExecutionMode::Manual) {
            throw new AiPipelineException('AI_MANUAL_AUDIT_PACKAGE_EXECUTION_INVALID');
        }
        if (! in_array($execution->status, [AiExecutionStatus::Pending, AiExecutionStatus::WaitingManual], true)) {
            throw new AiPipelineException('AI_MANUAL_AUDIT_PACKAGE_STATUS_INVALID');
        }

        $execution->loadMissing(['promptVersion.template']);
        $input = $this->inputBuilder->rebuildForExecution($execution);
        $prompt = $execution->promptVersion;
        $template = $prompt?->template;
        if (! $prompt || ! $template) {
            throw new AiPipelineException('AI_MANUAL_AUDIT_PACKAGE_PROMPT_MISSING');
        }
        $this->promptPolicy->assertReady($prompt);

        $sourceVersionId = (int) ($execution->input_manifest['source_version_id'] ?? 0);
        $sourceContentHash = (string) ($execution->input_manifest['source_content_hash'] ?? '');
        $supported = [
            'request_id' => $input->requestId,
            'input_revision' => $input->inputRevision,
            'canonical_plan' => CanonicalJson::encode($input->canonicalPlan->toArray()),
            'source_version_id' => $sourceVersionId,
            'source_content_hash' => $sourceContentHash,
            'input_manifest' => CanonicalJson::encode($execution->input_manifest),
            'output_schema' => CanonicalJson::encode($prompt->output_schema),
            'output_schema_version' => (string) $prompt->schema_version,
            'correlation_id' => $input->correlationId,
        ];

        $variables = [];
        foreach ($prompt->allowed_variables ?? [] as $name) {
            $variables[$name] = $supported[$name];
        }

        $renderedPrompt = $this->promptRenderer->render($prompt, $variables);
        $renderedHash = hash('sha256', $renderedPrompt);
        $payload = [
            'schema_version' => 1,
            'kind' => 'manual_audit',
            'execution' => [
                'id' => (int) $execution->id,
                'operation_key' => $execution->operation_key,
                'stage' => $execution->stage->value,
                'mode' => $execution->mode->value,
                'created_at' => $execution->created_at?->toIso8601String(),
            ],
            'request' => [
                'id' => $input->requestId,
                'input_revision' => $input->inputRevision,
                'correlation_id' => $input->correlationId,
            ],
            'source' => [
                'document_version_id' => $sourceVersionId,
                'content_sha256' => $sourceContentHash,
                'canonical_plan' => $input->canonicalPlan->toArray(),
            ],
            'prompt' => [
                'template_key' => $template->key,
                'version_id' => (int) $prompt->id,
                'version_number' => (int) $prompt->number,
                'checksum' => $prompt->checksum,
                'rendered_sha256' => $renderedHash,
                'rendered' => $renderedPrompt,
            ],
            'output' => [
                'schema_version' => $prompt->schema_version,
                'schema' => $prompt->output_schema,
                'return_json_only' => true,
            ],
            'input_manifest' => $execution->input_manifest,
        ];

        $json = CanonicalJson::encode($payload) . PHP_EOL;
        $checksum = hash('sha256', $json);
        $disk = (string) config('ai.manual.disk', 'private');
        $prefix = trim((string) config('ai.manual.prefix', 'ai/manual'), '/');
        $path = $prefix . '/audit/execution-' . $execution->id . '.json';

        $existing = AiManualPackage::query()->where('ai_execution_id', $execution->id)->first();
        if ($existing) {
            $this->assertExistingPackage($existing, $disk, $path, $checksum);
            $this->ensureStoredBytes($disk, $path, $json, $checksum);
            $this->finalizeExecution($execution->id, $renderedHash);
            return $existing;
        }

        $stored = $this->ensureStoredBytes($disk, $path, $json, $checksum);

        return DB::transaction(function () use ($execution, $disk, $path, $checksum, $renderedHash, $stored): AiManualPackage {
            /** @var AiExecution $locked */
            $locked = AiExecution::query()->whereKey($execution->id)->lockForUpdate()->firstOrFail();
            $existing = AiManualPackage::query()->where('ai_execution_id', $locked->id)->first();
            if ($existing) {
                $this->assertExistingPackage($existing, $disk, $path, $checksum);
                return $existing;
            }

            $package = AiManualPackage::query()->create([
                'ai_execution_id' => $locked->id,
                'disk' => $disk,
                'path' => $path,
                'checksum' => $checksum,
                'size_bytes' => strlen($stored),
            ]);

            $locked->forceFill([
                'rendered_prompt_hash' => $renderedHash,
                'status' => AiExecutionStatus::WaitingManual->value,
                'error_code' => null,
                'sanitized_error' => null,
            ])->save();

            return $package;
        });
    }

    private function finalizeExecution(int $executionId, string $renderedHash): void
    {
        DB::transaction(function () use ($executionId, $renderedHash): void {
            $execution = AiExecution::query()->whereKey($executionId)->lockForUpdate()->firstOrFail();
            if (! in_array($execution->status, [AiExecutionStatus::Pending, AiExecutionStatus::WaitingManual], true)) {
                return;
            }
            $execution->forceFill([
                'rendered_prompt_hash' => $renderedHash,
                'status' => AiExecutionStatus::WaitingManual->value,
                'error_code' => null,
                'sanitized_error' => null,
            ])->save();
        });
    }

    private function assertExistingPackage(AiManualPackage $existing, string $disk, string $path, string $checksum): void
    {
        if ($existing->checksum !== $checksum || $existing->disk !== $disk || $existing->path !== $path) {
            throw new AiPipelineException('AI_MANUAL_AUDIT_PACKAGE_IMMUTABLE_CONFLICT');
        }
    }

    private function ensureStoredBytes(string $disk, string $path, string $json, string $checksum): string
    {
        if (! Storage::disk($disk)->exists($path)
            || hash('sha256', Storage::disk($disk)->get($path)) !== $checksum) {
            Storage::disk($disk)->put($path, $json);
        }
        if (! Storage::disk($disk)->exists($path)) {
            throw new AiPipelineException('AI_MANUAL_AUDIT_PACKAGE_WRITE_FAILED');
        }
        $stored = Storage::disk($disk)->get($path);
        if (hash('sha256', $stored) !== $checksum) {
            throw new AiPipelineException('AI_MANUAL_AUDIT_PACKAGE_CHECKSUM_MISMATCH');
        }

        return $stored;
    }
}
