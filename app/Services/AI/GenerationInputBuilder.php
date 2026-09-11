<?php

namespace App\Services\AI;

use App\Data\AI\GenerationInput;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\PlanningRequest;
use App\Models\PromptVersion;
use App\Services\Documents\PlanningFormatGenerationContext;
use App\Support\AI\CanonicalJson;

final class GenerationInputBuilder
{
    public function __construct(private PlanningFormatGenerationContext $formatContext) {}

    public function build(
        PlanningRequest $request,
        PromptVersion $promptVersion,
        string $correlationId,
        string $operationKey,
    ): GenerationInput {
        $request->loadMissing(['currentInputVersion', 'segments']);
        $inputVersion = $request->currentInputVersion;

        if (! $inputVersion || $inputVersion->request_id !== $request->id) {
            throw new AiPipelineException('AI_GENERATION_INPUT_VERSION_MISSING');
        }
        if ((int) $inputVersion->revision !== (int) $request->input_revision) {
            throw new AiPipelineException('AI_GENERATION_INPUT_REVISION_MISMATCH');
        }
        if (! is_array($inputVersion->snapshot) || $inputVersion->snapshot !== $request->input_snapshot) {
            throw new AiPipelineException('AI_GENERATION_INPUT_SNAPSHOT_MISMATCH');
        }
        if (! is_array($request->calculation_snapshot) || (int) $request->planning_units < 1) {
            throw new AiPipelineException('AI_GENERATION_COMMERCIAL_SNAPSHOT_MISSING');
        }
        if (! $promptVersion->isPublished() || ! $promptVersion->checksum) {
            throw new AiPipelineException('AI_GENERATION_PROMPT_NOT_PUBLISHED');
        }

        $segments = $request->segments->map(fn ($segment) => [
            'sequence' => (int) $segment->sequence,
            'starts_on' => $segment->starts_on?->format('Y-m-d'),
            'ends_on' => $segment->ends_on?->format('Y-m-d'),
            'calendar_days' => (int) $segment->calendar_days,
            'units' => (int) $segment->units,
        ])->values()->all();

        $formatContext = $this->formatContext->build($request);

        $inputManifest = [
            'schema_version' => 1,
            'request_input_version_id' => (int) $inputVersion->id,
            'input_revision' => (int) $inputVersion->revision,
            'input_snapshot_sha256' => CanonicalJson::hash($inputVersion->snapshot),
            'commercial_snapshot_sha256' => CanonicalJson::hash($request->calculation_snapshot),
            'segments_sha256' => CanonicalJson::hash($segments),
            'format_version_id' => $formatContext['format_version_id'] ?? null,
            'format_context_sha256' => CanonicalJson::hash($formatContext),
            'prompt_version_id' => (int) $promptVersion->id,
            'prompt_checksum' => (string) $promptVersion->checksum,
            'output_schema_version' => (string) $promptVersion->schema_version,
            'correlation_id' => $correlationId,
        ];

        return new GenerationInput(
            requestId: (int) $request->id,
            inputRevision: (int) $inputVersion->revision,
            inputSnapshot: $inputVersion->snapshot,
            commercialSnapshot: $request->calculation_snapshot,
            planningUnits: (int) $request->planning_units,
            segments: $segments,
            formatContext: $formatContext,
            inputManifest: $inputManifest,
            promptVersionId: (int) $promptVersion->id,
            outputSchemaVersion: (string) $promptVersion->schema_version,
            correlationId: $correlationId,
            operationKey: $operationKey,
        );
    }

    public function rebuildForExecution(AiExecution $execution): GenerationInput
    {
        $execution->loadMissing(['request', 'promptVersion']);
        if (! $execution->request || ! $execution->promptVersion) {
            throw new AiPipelineException('AI_GENERATION_EXECUTION_INPUT_MISSING');
        }

        $correlationId = (string) ($execution->input_manifest['correlation_id'] ?? '');
        if ($correlationId === '') {
            throw new AiPipelineException('AI_GENERATION_CORRELATION_ID_MISSING');
        }

        $input = $this->build(
            $execution->request,
            $execution->promptVersion,
            $correlationId,
            $execution->operation_key,
        );

        if (CanonicalJson::hash($input->inputManifest) !== CanonicalJson::hash($execution->input_manifest)) {
            throw new AiPipelineException('AI_GENERATION_INPUT_MANIFEST_CHANGED');
        }

        return $input;
    }
}
