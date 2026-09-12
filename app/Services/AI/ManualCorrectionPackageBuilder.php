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

final class ManualCorrectionPackageBuilder
{
    public function __construct(
        private CorrectionInputBuilder $inputBuilder,
        private CorrectionPromptPolicy $promptPolicy,
        private ManualAiConfiguration $manualConfiguration,
        private PromptRenderer $promptRenderer,
        private AdaptiveCorrectionSchema $adaptiveCorrectionSchema,
    ) {}

    public function build(AiExecution $execution): AiManualPackage
    {
        $this->manualConfiguration->assertReady();
        if ($execution->stage !== AiExecutionStage::Correction || $execution->mode !== AiExecutionMode::Manual) {
            throw new AiPipelineException('AI_MANUAL_CORRECTION_PACKAGE_EXECUTION_INVALID');
        }
        if (! in_array($execution->status, [AiExecutionStatus::Pending, AiExecutionStatus::WaitingManual], true)) {
            throw new AiPipelineException('AI_MANUAL_CORRECTION_PACKAGE_STATUS_INVALID');
        }

        $execution->loadMissing(['promptVersion.template']);
        $input = $this->inputBuilder->rebuildForExecution($execution);
        $prompt = $execution->promptVersion;
        $template = $prompt?->template;
        if (! $prompt || ! $template) {
            throw new AiPipelineException('AI_MANUAL_CORRECTION_PACKAGE_PROMPT_MISSING');
        }
        $this->promptPolicy->assertReady($prompt);

        $adaptive = ($input->canonicalPlan->toArray()['schema_version'] ?? null) === CanonicalPlanValidator::ADAPTIVE_SCHEMA_VERSION;
        $effectiveSchema = $adaptive ? $this->adaptiveCorrectionSchema->build() : $prompt->output_schema;
        $effectiveSchemaVersion = $adaptive ? AdaptiveCorrectionSchema::CONTRACT_VERSION : (string) $prompt->schema_version;

        $sourceContentHash = (string) ($execution->input_manifest['source_content_hash'] ?? '');
        $sourceAuditReportHash = (string) ($execution->input_manifest['source_audit_report_hash'] ?? '');
        $auditReport = $this->auditReportFromFindings($input);
        $correctionReportHash = CanonicalJson::hash($auditReport);
        $supported = [
            'request_id' => $input->requestId,
            'input_revision' => $input->inputRevision,
            'canonical_plan' => CanonicalJson::encode($input->canonicalPlan->toArray()),
            'source_version_id' => $input->sourceVersionId,
            'source_content_hash' => $sourceContentHash,
            'audit_report' => CanonicalJson::encode($auditReport),
            'section_keys' => CanonicalJson::encode($input->sectionKeys),
            'correction_round' => $input->correctionRound,
            'input_manifest' => CanonicalJson::encode($execution->input_manifest),
            'output_schema' => CanonicalJson::encode($effectiveSchema),
            'output_schema_version' => $effectiveSchemaVersion,
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
            'kind' => 'manual_correction',
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
                'correction_round' => $input->correctionRound,
            ],
            'source' => [
                'kind' => $input->sourceKind,
                'document_version_id' => $input->sourceVersionId,
                'content_sha256' => $sourceContentHash,
                'canonical_plan' => $input->canonicalPlan->toArray(),
                'audit_execution_id' => $input->sourceAuditExecutionId,
                'review_id' => $input->sourceReviewId,
                'correction_request_id' => $input->sourceCorrectionRequestId,
                'source_audit_report_sha256' => $sourceAuditReportHash,
                'review_payload_sha256' => $execution->input_manifest['source_review_payload_hash'] ?? null,
                'audit_report_sha256' => $input->sourceKind === 'audit' ? $sourceAuditReportHash : $correctionReportHash,
                'audit_report' => $auditReport,
                'section_keys' => $input->sectionKeys,
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
                'schema_version' => $effectiveSchemaVersion,
                'schema' => $effectiveSchema,
                'return_json_only' => true,
            ],
            'input_manifest' => $execution->input_manifest,
        ];

        $json = CanonicalJson::encode($payload) . PHP_EOL;
        $checksum = hash('sha256', $json);
        $disk = (string) config('ai.manual.disk', 'private');
        $prefix = trim((string) config('ai.manual.prefix', 'ai/manual'), '/');
        $path = $prefix . '/correction/execution-' . $execution->id . '.json';

        $existing = AiManualPackage::query()->where('ai_execution_id', $execution->id)->first();
        if ($existing) {
            $this->assertExistingPackage($existing, $disk, $path, $checksum);
            $this->ensureStoredBytes($disk, $path, $json, $checksum);
            $this->finalizeExecution($execution->id, $renderedHash);

            return $existing;
        }

        $stored = $this->ensureStoredBytes($disk, $path, $json, $checksum);

        return DB::transaction(function () use ($execution, $disk, $path, $checksum, $renderedHash, $stored): AiManualPackage {
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

    /** @return array<string,mixed> */
    private function auditReportFromFindings(\App\Data\AI\CorrectionInput $input): array
    {
        return [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => array_map(fn ($finding) => $finding->toArray(), $input->findings),
        ];
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
            throw new AiPipelineException('AI_MANUAL_CORRECTION_PACKAGE_IMMUTABLE_CONFLICT');
        }
    }

    private function ensureStoredBytes(string $disk, string $path, string $json, string $checksum): string
    {
        if (! Storage::disk($disk)->exists($path)
            || hash('sha256', Storage::disk($disk)->get($path)) !== $checksum) {
            Storage::disk($disk)->put($path, $json);
        }
        if (! Storage::disk($disk)->exists($path)) {
            throw new AiPipelineException('AI_MANUAL_CORRECTION_PACKAGE_WRITE_FAILED');
        }
        $stored = Storage::disk($disk)->get($path);
        if (hash('sha256', $stored) !== $checksum) {
            throw new AiPipelineException('AI_MANUAL_CORRECTION_PACKAGE_CHECKSUM_MISMATCH');
        }

        return $stored;
    }
}
