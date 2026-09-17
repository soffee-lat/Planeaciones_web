<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ProcessOutboxEvent;
use App\Models\OutboxEvent;
use App\Services\AI\CanonicalPlanAssembler;
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

    public function test_container_resolves_canonical_assembler_without_template_override(): void
    {
        $this->assertSame(
            CanonicalPlanAssembler::class,
            app(CanonicalPlanAssembler::class)::class,
        );
    }

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
        $this->assertArrayNotHasKey('format_version_id', $execution->input_manifest);
        $this->assertArrayNotHasKey('format_context_sha256', $execution->input_manifest);

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

        $this->assertArrayNotHasKey('format_context', $package);
        $this->assertArrayNotHasKey('format_version_id', $package['request']);
        $this->assertArrayNotHasKey('format_version_id', $package['input_manifest']);
        $this->assertArrayNotHasKey('format_context_sha256', $package['input_manifest']);
        $this->assertSame('generated_plan_draft_v1', $package['output']['schema_version']);
        $this->assertSame('generated_plan_draft_v1', $package['output']['schema']['properties']['contract_version']['const']);
        $this->assertArrayHasKey('sessions', $package['output']['schema']['properties']);

        $serialized = json_encode($package, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('template_contract', $serialized);
        $this->assertStringNotContainsString('adaptive_template_generation_v1', $serialized);
        $this->assertStringNotContainsString('canonical_adaptive_plan_v1', $serialized);
    }
}
