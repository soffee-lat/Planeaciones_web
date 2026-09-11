<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Enums\PromptCategory;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class TemplateAwareGenerationTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;
    use CreatesInstitutionalFormatScenario;

    public function test_formato_lleno_aporta_campo_custom_y_ejemplo_al_paquete_de_generacion(): void
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/template-aware-test',
        ]);

        $request = $this->draft();
        $owner = $request->owner;
        $bytes = $this->institutionalTemplateBytes([], false, [
            'Título de la planeación:',
            'Vinculación con las familias:',
            'Las familias participan en una actividad breve relacionada con el proyecto.',
        ]);
        $draft = $this->institutionalDraftFromBytes($owner->id, $bytes);
        $formatVersion = app(AnalyzeInstitutionalFormatVersion::class)->execute($draft['version'], $owner);
        $sample = app(RenderInstitutionalFormatSample::class)->execute($formatVersion, $owner);
        app(ReviewInstitutionalFormatSample::class)->approve($sample, $owner, 'El ejemplo respeta el formato.');
        $formatVersion = app(PublishFormatVersion::class)->execute($formatVersion->fresh(), $owner);

        $profile = $request->group()->with('profile')->firstOrFail()->profile;
        app(UpdateGroupProfile::class)->execute($owner, $profile, [
            'preferred_format_id' => $formatVersion->format_id,
        ]);

        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishGenerationPrompt();

        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '91919191-9191-4919-8919-919191919191',
        );
        $event = OutboxEvent::query()->where('aggregate_id', $request->id)->sole();
        app(ProcessOutboxEvent::class)->execute($event);

        $execution = $execution->fresh(['manualPackage']);
        $request = $request->fresh();
        $this->assertSame($formatVersion->id, $execution->format_version_id);
        $this->assertSame($formatVersion->id, $request->format_version_id);

        $package = $execution->manualPackage;
        $this->assertNotNull($package);
        $payload = json_decode(
            Storage::disk($package->disk)->get($package->path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $this->assertSame($formatVersion->id, $payload['format_context']['format_version_id']);
        $this->assertSame('filled_example', $payload['format_context']['source_content_mode']);

        $field = collect($payload['format_context']['custom_fields'])
            ->first(fn (array $item): bool => $item['label'] === 'Vinculación con las familias');
        $this->assertIsArray($field);
        $this->assertSame('ai', $field['source']);
        $this->assertTrue($field['required']);
        $this->assertSame(
            'Las familias participan en una actividad breve relacionada con el proyecto.',
            $field['example'],
        );

        $key = $field['key'];
        $this->assertContains($key, $payload['output']['schema']['properties']['custom']['required']);
        $this->assertArrayHasKey($key, $payload['output']['schema']['properties']['custom']['properties']);
        $this->assertStringContainsString('Vinculación con las familias', $payload['prompt']['rendered']);
        $this->assertStringContainsString('Las familias participan en una actividad breve', $payload['prompt']['rendered']);
    }

    private function publishGenerationPrompt(): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.generation',
            'category' => PromptCategory::Generation->value,
        ]);
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => 'INPUT={{input_snapshot}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['input_snapshot', 'output_schema'],
        ]);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }
}
