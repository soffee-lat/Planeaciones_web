<?php

namespace App\Actions\AI;

use App\Enums\AiExecutionStage;
use App\Enums\OutboxEventType;
use App\Exceptions\AiPipelineException;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Services\AI\ManualGenerationPackageBuilder;
use App\Services\AI\ManualAuditPackageBuilder;
use App\Services\AI\RequestBlockManager;
use Illuminate\Support\Facades\DB;
use Throwable;

final class ProcessOutboxEvent
{
    public function __construct(
        private ManualGenerationPackageBuilder $manualPackageBuilder,
        private ManualAuditPackageBuilder $manualAuditPackageBuilder,
        private RequestBlockManager $blocks,
    ) {}

    public function execute(OutboxEvent $event): bool
    {
        $claimed = $this->claim($event->id);
        if (! $claimed) {
            return false;
        }

        try {
            $this->handle($claimed);
            $this->markPublished($claimed);
            return true;
        } catch (Throwable $e) {
            $this->releaseAfterFailure($claimed, $e);
            throw $e;
        }
    }

    private function claim(int $eventId): ?OutboxEvent
    {
        return DB::transaction(function () use ($eventId): ?OutboxEvent {
            /** @var OutboxEvent|null $event */
            $event = OutboxEvent::query()->whereKey($eventId)->lockForUpdate()->first();
            if (! $event || $event->published_at !== null || $event->available_at->isFuture()) {
                return null;
            }
            if ($event->lease_expires_at !== null && $event->lease_expires_at->isFuture()) {
                return null;
            }

            $now = now();
            $event->forceFill([
                'attempts' => (int) $event->attempts + 1,
                'claimed_at' => $now,
                'lease_expires_at' => $now->copy()->addSeconds(max(1, (int) config('ai.outbox.lease_seconds', 120))),
            ])->save();

            return $event->fresh();
        });
    }

    private function handle(OutboxEvent $event): void
    {
        match ($event->type) {
            OutboxEventType::PlanningGenerationRequested => $this->handleGenerationRequested($event),
            OutboxEventType::PlanningAuditRequested => $this->handleAuditRequested($event),
        };
    }

    private function handleGenerationRequested(OutboxEvent $event): void
    {
        $executionId = (int) ($event->payload['ai_execution_id'] ?? 0);
        $requestId = (int) ($event->payload['request_id'] ?? 0);
        if ($executionId < 1 || $requestId < 1 || $requestId !== (int) $event->aggregate_id) {
            throw new AiPipelineException('AI_OUTBOX_PAYLOAD_INVALID');
        }

        /** @var AiExecution|null $execution */
        $execution = AiExecution::query()->find($executionId);
        if (! $execution
            || $execution->request_id !== $requestId
            || $execution->stage !== \App\Enums\AiExecutionStage::Generation) {
            throw new AiPipelineException('AI_OUTBOX_EXECUTION_MISMATCH');
        }

        $this->manualPackageBuilder->build($execution);
    }

    private function handleAuditRequested(OutboxEvent $event): void
    {
        $executionId = (int) ($event->payload['ai_execution_id'] ?? 0);
        $requestId = (int) ($event->payload['request_id'] ?? 0);
        $sourceVersionId = (int) ($event->payload['source_version_id'] ?? 0);
        if ($executionId < 1 || $requestId < 1 || $sourceVersionId < 1 || $requestId !== (int) $event->aggregate_id) {
            throw new AiPipelineException('AI_AUDIT_OUTBOX_PAYLOAD_INVALID');
        }

        /** @var AiExecution|null $execution */
        $execution = AiExecution::query()->find($executionId);
        if (! $execution
            || $execution->request_id !== $requestId
            || $execution->stage !== AiExecutionStage::Audit
            || (int) ($execution->input_manifest['source_version_id'] ?? 0) !== $sourceVersionId) {
            throw new AiPipelineException('AI_AUDIT_OUTBOX_EXECUTION_MISMATCH');
        }

        $this->manualAuditPackageBuilder->build($execution);
    }

    private function markPublished(OutboxEvent $claimed): void
    {
        DB::transaction(function () use ($claimed): void {
            /** @var OutboxEvent|null $event */
            $event = OutboxEvent::query()->whereKey($claimed->id)->lockForUpdate()->first();
            if (! $event || $event->published_at !== null) {
                return;
            }

            $request = PlanningRequest::query()->whereKey($event->aggregate_id)->lockForUpdate()->first();
            if ($request) {
                $this->blocks->resolve($request, 'ai_failed', $this->stageForEvent($event)->value);
            }

            $event->forceFill([
                'published_at' => now(),
                'claimed_at' => null,
                'lease_expires_at' => null,
                'last_error_code' => null,
            ])->save();
        });
    }

    private function releaseAfterFailure(OutboxEvent $claimed, Throwable $failure): void
    {
        $errorCode = $failure instanceof AiPipelineException
            ? $failure->errorCode
            : 'AI_OUTBOX_PROCESSING_FAILED';

        DB::transaction(function () use ($claimed, $errorCode): void {
            /** @var OutboxEvent|null $event */
            $event = OutboxEvent::query()->whereKey($claimed->id)->lockForUpdate()->first();
            if (! $event || $event->published_at !== null) {
                return;
            }

            $event->forceFill([
                'available_at' => now()->addSeconds(max(1, (int) config('ai.outbox.retry_seconds', 30))),
                'claimed_at' => null,
                'lease_expires_at' => null,
                'last_error_code' => $errorCode,
            ])->save();

            $executionId = (int) ($event->payload['ai_execution_id'] ?? 0);
            if ($executionId > 0) {
                $execution = AiExecution::query()->whereKey($executionId)->lockForUpdate()->first();
                if ($execution && $execution->status !== \App\Enums\AiExecutionStatus::Succeeded) {
                    $execution->forceFill([
                        'error_code' => $errorCode,
                        'sanitized_error' => $this->stageForEvent($event) === AiExecutionStage::Audit
                            ? 'audit_dispatch_failed'
                            : 'generation_dispatch_failed',
                    ])->save();
                }
            }

            $request = PlanningRequest::query()->whereKey($event->aggregate_id)->lockForUpdate()->first();
            if ($request) {
                $this->blocks->open(
                    $request,
                    'ai_failed',
                    $this->stageForEvent($event)->value,
                    ['error_code' => $errorCode, 'attempts' => (int) $event->attempts],
                    (string) ($event->payload['correlation_id'] ?? '') ?: null,
                );
            }
        });
    }

    private function stageForEvent(OutboxEvent $event): AiExecutionStage
    {
        return match ($event->type) {
            OutboxEventType::PlanningGenerationRequested => AiExecutionStage::Generation,
            OutboxEventType::PlanningAuditRequested => AiExecutionStage::Audit,
        };
    }
}
