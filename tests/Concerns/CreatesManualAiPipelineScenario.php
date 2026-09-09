<?php

namespace Tests\Concerns;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Enums\AiExecutionStage;
use App\Enums\OutboxEventType;
use App\Enums\PromptCategory;
use App\Models\AiExecution;
use App\Models\DocumentVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\Storage;

trait CreatesManualAiPipelineScenario
{
    /** @return array{generation:PromptVersion,audit:PromptVersion,correction:PromptVersion} */
    protected function publishManualAiPrompts(bool $badCorrectionSchema = false): array
    {
        $generation = $this->publishManualPrompt(
            PromptCategory::Generation,
            'planning.generation',
            'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
            ['input_snapshot', 'output_schema'],
            'generated_plan_draft_v1',
            resource_path('schemas/ai/generated_plan_draft_v1.schema.json'),
        );
        $audit = $this->publishManualPrompt(
            PromptCategory::Audit,
            'planning.audit',
            'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
            ['canonical_plan', 'output_schema'],
            'audit_result_v1',
            resource_path('schemas/ai/audit_result_v1.schema.json'),
        );
        $correction = $this->publishManualPrompt(
            PromptCategory::Correction,
            'planning.correction',
            'CANONICAL={{canonical_plan}} AUDIT={{audit_report}} SCOPE={{section_keys}} OUTPUT={{output_schema}}',
            ['canonical_plan', 'audit_report', 'section_keys', 'output_schema'],
            'correction_result_v1',
            resource_path('schemas/ai/correction_result_v1.schema.json'),
            $badCorrectionSchema,
        );

        return compact('generation', 'audit', 'correction');
    }

    /**
     * @param array<string,mixed> $planLimits
     * @return array{request:PlanningRequest,audit:AiExecution,version:DocumentVersion}
     */
    protected function waitingManualAuditScenario(array $planLimits = [], bool $badCorrectionSchema = false): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/phase4e-test',
            'ai.internal_correction.max_rounds' => 2,
            'ai.internal_correction.max_known_cost' => null,
            'ai.internal_correction.cost_currency' => null,
        ]);
        Storage::fake('private');

        $request = $this->draft();
        $this->period($request, $planLimits);
        $request = $this->authorize($this->confirm($request));
        $this->publishManualAiPrompts($badCorrectionSchema);

        $generation = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '41414141-4141-4141-8141-414141414141',
        );
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $generation->id . ':generation-dispatch')->sole(),
        );
        $version = app(ImportManualGenerationResult::class)->execute(
            $generation->fresh(),
            $this->generatedDraftFor($request->fresh(['currentInputVersion'])),
        );
        $audit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $audit->id . ':audit-dispatch')->sole(),
        );

        return [
            'request' => $request->fresh(),
            'audit' => $audit->fresh(),
            'version' => $version->fresh(),
        ];
    }

    /**
     * @param array<string,mixed> $planLimits
     * @return array{request:PlanningRequest,audit:AiExecution,version:DocumentVersion}
     */
    protected function succeededAuditScenario(
        bool $passed,
        array $planLimits = [],
        string $path = '/sessions/0',
        string $code = 'LANGUAGE_QUALITY',
        string $severity = 'medium',
        bool $badCorrectionSchema = false,
    ): array {
        $scene = $this->waitingManualAuditScenario($planLimits, $badCorrectionSchema);
        $payload = $passed
            ? [
                'schema_version' => 'audit_result_v1',
                'passed' => true,
                'findings' => [],
            ]
            : [
                'schema_version' => 'audit_result_v1',
                'passed' => false,
                'findings' => [[
                    'code' => $code,
                    'severity' => $severity,
                    'json_path' => $path,
                    'explanation' => 'Hallazgo ficticio de calidad para pruebas de corrección interna.',
                    'expected_correction' => 'Corregir solo el alcance indicado y conservar el currículo congelado.',
                ]],
            ];

        app(ImportManualAuditResult::class)->execute($scene['audit'], $payload);
        $scene['audit'] = $scene['audit']->fresh();
        $scene['request'] = $scene['request']->fresh();

        return $scene;
    }

    private function publishManualPrompt(
        PromptCategory $category,
        string $key,
        string $body,
        array $allowedVariables,
        string $schemaVersion,
        string $schemaPath,
        bool $badSchema = false,
    ): PromptVersion {
        $template = PromptTemplate::query()->where('key', $key)->first();
        if ($template !== null && $template->active_version_id !== null && ! $badSchema) {
            return PromptVersion::query()->findOrFail($template->active_version_id);
        }

        $template ??= PromptTemplate::factory()->create([
            'key' => $key,
            'category' => $category->value,
        ]);
        $schema = $badSchema
            ? ['type' => 'object']
            : json_decode(file_get_contents($schemaPath), true, 512, JSON_THROW_ON_ERROR);
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => $body,
            'allowed_variables' => $allowedVariables,
            'output_schema' => $schema,
            'schema_version' => $schemaVersion,
        ]);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }
}
