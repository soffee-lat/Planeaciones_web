<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Enums\AiExecutionStage;
use App\Enums\PlanningRequestStatus;
use App\Models\AiExecution;
use App\Models\OutboxEvent;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class CanonicalGenerationIndependenceTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesInstitutionalFormatScenario;
    use CreatesManualAiPipelineScenario;

    public function test_generation_package_is_canonical_even_when_teacher_has_institutional_format(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/canonical-generation-test',
        ]);
        Storage::fake('private');

        $request = $this->draft();
        $institutional = $this->publishedInstitutionalFormat($request->owner_id);
        $request->group->profile->forceFill([
            'preferred_format_id' => $institutional->format_id,
        ])->save();

        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishManualAiPrompts();

        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '51515151-5151-4151-8151-515151515151',
        );

        $this->assertNull($execution->format_version_id);
        $this->assertNull($request->fresh()->format_version_id);
        $this->assertNull($execution->input_manifest['format_version_id']);

        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()
                ->where('event_key', 'ai-execution:' . $execution->id . ':generation-dispatch')
                ->sole(),
        );

        $execution = $execution->fresh(['manualPackage']);
        $this->assertNotNull($execution->manualPackage);
        $package = json_decode(
            Storage::disk($execution->manualPackage->disk)->get($execution->manualPackage->path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame('canonical', $package['format_context']['generation_scope']);
        $this->assertNull($package['format_context']['format_version_id']);
        $this->assertNull($package['format_context']['renderer']);
        $this->assertNull($package['format_context']['template_contract']);
        $this->assertSame([], $package['format_context']['custom_fields']);
        $this->assertSame('generated_plan_draft_v1', $package['output']['schema_version']);
        $this->assertSame('generated_plan_draft_v1', $package['output']['schema']['properties']['contract_version']['const']);
        $this->assertArrayHasKey('sessions', $package['output']['schema']['properties']);
        $this->assertArrayNotHasKey('format_version_id', $package['request']);

        $version = app(ImportManualGenerationResult::class)->execute(
            $execution,
            $this->generatedDraftFor($request->fresh(['currentInputVersion'])),
        );

        $this->assertNotNull($version->id);
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);
        $this->assertSame(
            1,
            AiExecution::query()
                ->where('request_id', $request->id)
                ->where('stage', AiExecutionStage::Audit->value)
                ->count(),
        );
    }
}
