<?php

namespace App\Services\AI;

use App\Data\AI\AuditInput;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\PlanningRequestStatus;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Str;

final class AuditInputBuilder
{
    public function __construct(private CanonicalPlanValidator $canonicalValidator) {}

    public function rebuildForExecution(AiExecution $execution): AuditInput
    {
        $execution->loadMissing(['request', 'promptVersion']);
        $request = $execution->request;
        $prompt = $execution->promptVersion;

        if (! $request || ! $prompt
            || $execution->stage !== AiExecutionStage::Audit
            || $execution->mode !== AiExecutionMode::Manual) {
            throw new AiPipelineException('AI_AUDIT_EXECUTION_INPUT_INVALID');
        }
        if ($request->status !== PlanningRequestStatus::AUDITORIA_IA
            || (int) $request->input_revision !== (int) $execution->input_revision) {
            throw new AiPipelineException('AI_AUDIT_REQUEST_STALE');
        }

        $manifest = $execution->input_manifest;
        $sourceVersionId = (int) ($manifest['source_version_id'] ?? 0);
        $sourceContentHash = (string) ($manifest['source_content_hash'] ?? '');
        $correlationId = strtolower(trim((string) ($manifest['correlation_id'] ?? '')));
        if ($sourceVersionId < 1 || preg_match('/^[0-9a-f]{64}$/', $sourceContentHash) !== 1 || ! Str::isUuid($correlationId)) {
            throw new AiPipelineException('AI_AUDIT_INPUT_MANIFEST_INVALID');
        }

        /** @var DocumentVersion|null $version */
        $version = DocumentVersion::query()
            ->with('document')
            ->whereKey($sourceVersionId)
            ->first();
        if (! $version || ! $version->document
            || (int) $version->document->request_id !== (int) $request->id
            || (int) $version->input_revision !== (int) $request->input_revision
            || $version->content_hash !== $sourceContentHash
            || CanonicalJson::hash($version->content) !== $sourceContentHash) {
            throw new AiPipelineException('AI_AUDIT_SOURCE_VERSION_STALE');
        }

        $canonical = $this->canonicalValidator->validate($version->content);

        return new AuditInput(
            requestId: (int) $request->id,
            inputRevision: (int) $request->input_revision,
            canonicalPlan: $canonical,
            promptVersionId: (int) $prompt->id,
            correlationId: $correlationId,
            operationKey: $execution->operation_key,
        );
    }
}
