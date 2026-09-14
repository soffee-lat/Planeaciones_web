<?php

namespace App\Actions\AI;

use App\Actions\Commerce\ConsumePlanningReservation;
use App\Enums\AiExecutionMode;
use App\Enums\AiExecutionStage;
use App\Enums\AiExecutionStatus;
use App\Enums\OutboxEventType;
use App\Enums\PlanningRequestStatus;
use App\Enums\PromptCategory;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Models\UsageReservation;
use App\Services\AI\GenerationInputBuilder;
use App\Services\AI\GenerationPromptPolicy;
use App\Services\AI\ManualAiConfiguration;
use App\Services\Curriculum\ProductionCurriculumPolicy;
use App\Services\Planning\PlanningRequestStateMachine;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class DispatchPlanningGeneration
{
    public function __construct(
        private GenerationInputBuilder $inputBuilder,
        private GenerationPromptPolicy $promptPolicy,
        private ManualAiConfiguration $manualConfiguration,
        private PlanningRequestStateMachine $stateMachine,
        private ConsumePlanningReservation $consumeReservation,
        private ProductionCurriculumPolicy $curriculumPolicy,
    ) {}

    public function execute(PlanningRequest $request, ?string $correlationId = null): AiExecution
    {
        $mode = AiExecutionMode::tryFrom((string) config('ai.mode', 'manual'));
        if ($mode === null) {
            throw new AiPipelineException('AI_MODE_INVALID');
        }
        if ($mode !== AiExecutionMode::Manual) {
            throw new AiPipelineException('AI_API_PROVIDER_NOT_CONFIGURED');
        }
        $this->manualConfiguration->assertReady();

        $correlationId ??= (string) Str::uuid();
        if (! Str::isUuid($correlationId)) {
            throw new AiPipelineException('AI_CORRELATION_ID_INVALID');
        }
        $correlationId = strtolower($correlationId);

        return DB::transaction(function () use ($request, $mode, $correlationId): AiExecution {
            /** @var PlanningRequest $fresh */
            $fresh = PlanningRequest::query()->whereKey($request->id)->lockForUpdate()->firstOrFail();

            if ($fresh->status === PlanningRequestStatus::GENERACION_IA) {
                $existing = AiExecution::query()
                    ->where('request_id', $fresh->id)
                    ->where('stage', AiExecutionStage::Generation->value)
                    ->where('input_revision', $fresh->input_revision)
                    ->orderByDesc('id')
                    ->first();
                if (! $existing) {
                    throw new AiPipelineException('AI_GENERATION_EXECUTION_MISSING');
                }
                return $existing;
            }

            if ($fresh->status !== PlanningRequestStatus::LISTA_PARA_PROCESAR
                || $fresh->commercial_authorized_at === null
                || $fresh->current_version_id === null
                || (int) $fresh->planning_units < 1) {
                throw new AiPipelineException('AI_GENERATION_REQUEST_NOT_READY');
            }

            try {
                $this->curriculumPolicy->assertSnapshotEligible((array) ($fresh->input_snapshot ?? []));
            } catch (\RuntimeException $error) {
                if ($error->getMessage() === ProductionCurriculumPolicy::NOT_READY) {
                    throw new AiPipelineException('AI_GENERATION_CURRICULUM_NOT_PRODUCTION_READY');
                }
                throw $error;
            }

            $this->stateMachine->assertCanTransition($fresh->status, PlanningRequestStatus::GENERACION_IA);

            $templateKey = trim((string) config('ai.prompts.generation_key', 'planning.generation'));
            /** @var PromptTemplate|null $template */
            $template = PromptTemplate::query()
                ->where('key', $templateKey)
                ->where('category', PromptCategory::Generation->value)
                ->lockForUpdate()
                ->first();
            if (! $template || $template->active_version_id === null) {
                throw new AiPipelineException('AI_GENERATION_ACTIVE_PROMPT_MISSING');
            }

            /** @var PromptVersion|null $prompt */
            $prompt = PromptVersion::query()
                ->whereKey($template->active_version_id)
                ->where('template_id', $template->id)
                ->lockForUpdate()
                ->first();
            if (! $prompt) {
                throw new AiPipelineException('AI_GENERATION_ACTIVE_PROMPT_INVALID');
            }
            $this->promptPolicy->assertReady($prompt);

            $operationKey = sprintf(
                'planning-request:%d:generation:input:%d:prompt:%d',
                $fresh->id,
                $fresh->input_revision,
                $prompt->id,
            );

            $existing = AiExecution::query()->where('operation_key', $operationKey)->lockForUpdate()->first();
            if ($existing) {
                return $existing;
            }

            $input = $this->inputBuilder->build($fresh, $prompt, $correlationId, $operationKey);

            /** @var UsageReservation|null $planningReservation */
            $planningReservation = UsageReservation::query()
                ->where('planning_request_id', $fresh->id)
                ->where('subscription_period_id', $fresh->subscription_period_id)
                ->where('resource', UsageResource::Planning->value)
                ->where('operation_key', 'planning-request:' . $fresh->id . ':planning')
                ->lockForUpdate()
                ->first();
            if (! $planningReservation
                || (int) $planningReservation->quantity !== (int) $fresh->planning_units
                || ! in_array($planningReservation->status, [UsageReservationStatus::Reserved, UsageReservationStatus::Consumed], true)) {
                throw new AiPipelineException('AI_GENERATION_PLANNING_RESERVATION_INVALID');
            }

            ($this->consumeReservation)($planningReservation);

            /** @var AiExecution $execution */
            $execution = AiExecution::query()->create([
                'request_id' => $fresh->id,
                'format_version_id' => null,
                'stage' => AiExecutionStage::Generation->value,
                'mode' => $mode->value,
                'provider' => null,
                'model' => null,
                'prompt_version_id' => $prompt->id,
                'input_revision' => $input->inputRevision,
                'input_manifest' => $input->inputManifest,
                'rendered_prompt_hash' => null,
                'private_payload_file_id' => null,
                'operation_key' => $operationKey,
                'status' => AiExecutionStatus::Pending->value,
                'started_at' => null,
                'finished_at' => null,
                'duration_ms' => null,
                'error_code' => null,
                'sanitized_error' => null,
                'estimated_cost' => null,
                'actual_cost' => null,
                'cost_currency' => null,
                'resulting_version_id' => null,
                'audit_report' => null,
            ]);

            $fresh->forceFill([
                'status' => PlanningRequestStatus::GENERACION_IA->value,
                'lock_version' => (int) $fresh->lock_version + 1,
            ])->save();
            $fresh->stateEvents()->create([
                'from_status' => PlanningRequestStatus::LISTA_PARA_PROCESAR->value,
                'to_status' => PlanningRequestStatus::GENERACION_IA->value,
                'actor_id' => null,
                'actor_type' => 'system',
                'reason' => 'generation_dispatched',
                'correlation_id' => $correlationId,
            ]);

            OutboxEvent::query()->create([
                'event_key' => 'ai-execution:' . $execution->id . ':generation-dispatch',
                'type' => OutboxEventType::PlanningGenerationRequested->value,
                'aggregate_id' => $fresh->id,
                'payload' => [
                    'request_id' => (int) $fresh->id,
                    'ai_execution_id' => (int) $execution->id,
                    'input_revision' => (int) $fresh->input_revision,
                    'correlation_id' => $correlationId,
                ],
                'published_at' => null,
                'attempts' => 0,
                'available_at' => now(),
            ]);

            return $execution->fresh(['request', 'promptVersion']);
        }, attempts: 3);
    }
}
