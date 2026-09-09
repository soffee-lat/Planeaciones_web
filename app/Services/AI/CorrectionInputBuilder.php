<?php

namespace App\Services\AI;

use App\Data\AI\CorrectionInput;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Str;

final class CorrectionInputBuilder
{
    public function __construct(
        private CanonicalPlanValidator $canonicalValidator,
        private AuditResultValidator $auditResultValidator,
        private InternalCorrectionPolicy $correctionPolicy,
    ) {}

    public function rebuildForExecution(AiExecution $execution): CorrectionInput
    {
        $execution->loadMissing(['request', 'promptVersion']);
        $request = $execution->request;
        $prompt = $execution->promptVersion;

        if (! $request || ! $prompt
            || $execution->stage !== AiExecutionStage::Correction
            || $execution->mode !== AiExecutionMode::Manual) {
            throw new AiPipelineException('AI_CORRECTION_EXECUTION_INPUT_INVALID');
        }
        if ($request->status !== PlanningRequestStatus::CORRECCION_IA
            || (int) $request->input_revision !== (int) $execution->input_revision) {
            throw new AiPipelineException('AI_CORRECTION_REQUEST_STALE');
        }

        $manifest = $execution->input_manifest;
        $sourceVersionId = (int) ($manifest['source_version_id'] ?? 0);
        $sourceContentHash = (string) ($manifest['source_content_hash'] ?? '');
        $sourceAuditExecutionId = (int) ($manifest['source_audit_execution_id'] ?? 0);
        $sourceAuditReportHash = (string) ($manifest['source_audit_report_hash'] ?? '');
        $round = (int) ($manifest['correction_round'] ?? 0);
        $sectionKeys = $manifest['section_keys'] ?? null;
        $correlationId = strtolower(trim((string) ($manifest['correlation_id'] ?? '')));

        if ($sourceVersionId < 1
            || $sourceAuditExecutionId < 1
            || $round < 1
            || preg_match('/^[0-9a-f]{64}$/', $sourceContentHash) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $sourceAuditReportHash) !== 1
            || ! is_array($sectionKeys)
            || $sectionKeys === []
            || ! Str::isUuid($correlationId)) {
            throw new AiPipelineException('AI_CORRECTION_INPUT_MANIFEST_INVALID');
        }

        $sectionKeys = array_values(array_unique(array_map('strval', $sectionKeys)));
        sort($sectionKeys, SORT_STRING);

        $version = DocumentVersion::query()->with('document')->whereKey($sourceVersionId)->first();
        if (! $version || ! $version->document
            || (int) $version->document->request_id !== (int) $request->id
            || (int) $version->document->current_version_id !== $sourceVersionId
            || (int) $version->input_revision !== (int) $request->input_revision
            || $version->content_hash !== $sourceContentHash
            || CanonicalJson::hash($version->content) !== $sourceContentHash) {
            throw new AiPipelineException('AI_CORRECTION_SOURCE_VERSION_STALE');
        }

        $audit = AiExecution::query()->whereKey($sourceAuditExecutionId)->first();
        if (! $audit
            || (int) $audit->request_id !== (int) $request->id
            || $audit->stage !== AiExecutionStage::Audit
            || $audit->status !== AiExecutionStatus::Succeeded
            || ! is_array($audit->audit_report)
            || ($audit->audit_report['passed'] ?? null) !== false
            || (int) ($audit->input_manifest['source_version_id'] ?? 0) !== $sourceVersionId
            || CanonicalJson::hash($audit->audit_report) !== $sourceAuditReportHash) {
            throw new AiPipelineException('AI_CORRECTION_SOURCE_AUDIT_STALE');
        }

        $auditResult = $this->auditResultValidator->validate($audit->audit_report);
        if ($this->correctionPolicy->sectionKeys($auditResult) !== $sectionKeys) {
            throw new AiPipelineException('AI_CORRECTION_SCOPE_MANIFEST_MISMATCH');
        }
        $canonical = $this->canonicalValidator->validate($version->content);

        return new CorrectionInput(
            requestId: (int) $request->id,
            inputRevision: (int) $request->input_revision,
            sourceVersionId: $sourceVersionId,
            sourceAuditExecutionId: $sourceAuditExecutionId,
            correctionRound: $round,
            canonicalPlan: $canonical,
            sectionKeys: $sectionKeys,
            findings: $auditResult->findings,
            promptVersionId: (int) $prompt->id,
            correlationId: $correlationId,
            operationKey: $execution->operation_key,
        );
    }
}
