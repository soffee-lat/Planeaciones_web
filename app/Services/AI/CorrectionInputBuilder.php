<?php

namespace App\Services\AI;

use App\Data\AI\CorrectionInput;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\HumanReviewStatus;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Exceptions\ClientCorrectionException;
use App\Exceptions\HumanReviewException;
use App\Models\AiExecution;
use App\Models\CorrectionRequest;
use App\Models\DocumentVersion;
use App\Models\HumanReview;
use App\Services\Planning\ClientCorrectionPolicy;
use App\Services\Review\HumanReviewCorrectionPolicy;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Str;

final class CorrectionInputBuilder
{
    public function __construct(
        private CanonicalPlanValidator $canonicalValidator,
        private AuditResultValidator $auditResultValidator,
        private InternalCorrectionPolicy $correctionPolicy,
        private HumanReviewCorrectionPolicy $humanReviewCorrectionPolicy,
        private ClientCorrectionPolicy $clientCorrectionPolicy,
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
        $sourceKind = (string) ($manifest['source_kind'] ?? 'audit');
        $sourceVersionId = (int) ($manifest['source_version_id'] ?? 0);
        $sourceContentHash = (string) ($manifest['source_content_hash'] ?? '');
        $sourceAuditExecutionId = (int) ($manifest['source_audit_execution_id'] ?? 0);
        $sourceAuditReportHash = (string) ($manifest['source_audit_report_hash'] ?? '');
        $sourceReviewId = isset($manifest['source_review_id']) ? (int) $manifest['source_review_id'] : null;
        $sourceReviewPayloadHash = (string) ($manifest['source_review_payload_hash'] ?? '');
        $sourceCorrectionRequestId = isset($manifest['source_correction_request_id']) ? (int) $manifest['source_correction_request_id'] : null;
        $sourceCorrectionPayloadHash = (string) ($manifest['source_correction_payload_hash'] ?? '');
        $round = (int) ($manifest['correction_round'] ?? 0);
        $sectionKeys = $manifest['section_keys'] ?? null;
        $correlationId = strtolower(trim((string) ($manifest['correlation_id'] ?? '')));

        if (! in_array($sourceKind, ['audit', 'human_review', 'client'], true)
            || $sourceVersionId < 1
            || $sourceAuditExecutionId < 1
            || $round < 1
            || preg_match('/^[0-9a-f]{64}$/', $sourceContentHash) !== 1
            || preg_match('/^[0-9a-f]{64}$/', $sourceAuditReportHash) !== 1
            || ! is_array($sectionKeys)
            || $sectionKeys === []
            || ! Str::isUuid($correlationId)) {
            throw new AiPipelineException('AI_CORRECTION_INPUT_MANIFEST_INVALID');
        }
        if ($sourceKind === 'human_review'
            && (($sourceReviewId ?? 0) < 1
                || preg_match('/^[0-9a-f]{64}$/', $sourceReviewPayloadHash) !== 1
                || $sourceCorrectionRequestId !== null
                || $sourceCorrectionPayloadHash !== '')) {
            throw new AiPipelineException('AI_CORRECTION_INPUT_MANIFEST_INVALID');
        }
        if ($sourceKind === 'client'
            && (($sourceCorrectionRequestId ?? 0) < 1
                || preg_match('/^[0-9a-f]{64}$/', $sourceCorrectionPayloadHash) !== 1
                || $sourceReviewId !== null
                || $sourceReviewPayloadHash !== '')) {
            throw new AiPipelineException('AI_CORRECTION_INPUT_MANIFEST_INVALID');
        }
        if ($sourceKind === 'audit'
            && ($sourceReviewId !== null || $sourceReviewPayloadHash !== ''
                || $sourceCorrectionRequestId !== null || $sourceCorrectionPayloadHash !== '')) {
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
            || (int) ($audit->input_manifest['source_version_id'] ?? 0) !== $sourceVersionId
            || CanonicalJson::hash($audit->audit_report) !== $sourceAuditReportHash) {
            throw new AiPipelineException('AI_CORRECTION_SOURCE_AUDIT_STALE');
        }

        if ($sourceKind === 'audit') {
            if (($audit->audit_report['passed'] ?? null) !== false) {
                throw new AiPipelineException('AI_CORRECTION_SOURCE_AUDIT_STALE');
            }
            $auditResult = $this->auditResultValidator->validate($audit->audit_report);
            $canonicalSchemaVersion = (string) ($version->content['schema_version'] ?? '');
            if ($this->correctionPolicy->sectionKeys($auditResult, $canonicalSchemaVersion) !== $sectionKeys) {
                throw new AiPipelineException('AI_CORRECTION_SCOPE_MANIFEST_MISMATCH');
            }
            $findings = $auditResult->findings;
        } elseif ($sourceKind === 'human_review') {
            if (($audit->audit_report['passed'] ?? null) !== true) {
                throw new AiPipelineException('AI_HUMAN_CORRECTION_PASSED_AUDIT_REQUIRED');
            }
            $review = HumanReview::query()->whereKey($sourceReviewId)->first();
            if (! $review
                || (int) $review->request_id !== (int) $request->id
                || (int) $review->version_id !== $sourceVersionId
                || $review->status !== HumanReviewStatus::ChangesRequested) {
                throw new AiPipelineException('AI_HUMAN_CORRECTION_SOURCE_REVIEW_STALE');
            }
            try {
                $context = $this->humanReviewCorrectionPolicy->buildForTerminalReview($review);
            } catch (HumanReviewException $e) {
                throw new AiPipelineException('AI_HUMAN_CORRECTION_SOURCE_REVIEW_STALE', $e->errorCode);
            }
            if ($context['payloadHash'] !== $sourceReviewPayloadHash || $context['sectionKeys'] !== $sectionKeys) {
                throw new AiPipelineException('AI_HUMAN_CORRECTION_SOURCE_REVIEW_STALE');
            }
            $findings = $context['findings'];
        } else {
            if (($audit->audit_report['passed'] ?? null) !== true) {
                throw new AiPipelineException('AI_CLIENT_CORRECTION_PASSED_AUDIT_REQUIRED');
            }
            $correction = CorrectionRequest::query()->with('reservation')->whereKey($sourceCorrectionRequestId)->first();
            if (! $correction) {
                throw new AiPipelineException('AI_CLIENT_CORRECTION_SOURCE_REQUEST_STALE');
            }
            try {
                $this->clientCorrectionPolicy->assertClientProcessing($correction, $request, $sourceVersionId);
            } catch (ClientCorrectionException $e) {
                throw new AiPipelineException('AI_CLIENT_CORRECTION_SOURCE_REQUEST_STALE', $e->errorCode);
            }
            if ($this->clientCorrectionPolicy->payloadHash($correction) !== $sourceCorrectionPayloadHash) {
                throw new AiPipelineException('AI_CLIENT_CORRECTION_SOURCE_REQUEST_STALE');
            }
            $correctionSections = array_values(array_unique(array_map('strval', $correction->section_keys ?? [])));
            sort($correctionSections, SORT_STRING);
            if ($correctionSections !== $sectionKeys) {
                throw new AiPipelineException('AI_CLIENT_CORRECTION_SCOPE_MANIFEST_MISMATCH');
            }
            $findings = $this->clientCorrectionPolicy->findings($correction);
        }

        $canonical = $this->canonicalValidator->validate($version->content);

        return new CorrectionInput(
            requestId: (int) $request->id,
            inputRevision: (int) $request->input_revision,
            sourceVersionId: $sourceVersionId,
            sourceAuditExecutionId: $sourceAuditExecutionId,
            sourceKind: $sourceKind,
            sourceReviewId: $sourceReviewId,
            sourceCorrectionRequestId: $sourceCorrectionRequestId,
            correctionRound: $round,
            canonicalPlan: $canonical,
            sectionKeys: $sectionKeys,
            findings: $findings,
            promptVersionId: (int) $prompt->id,
            correlationId: $correlationId,
            operationKey: $execution->operation_key,
        );
    }
}
