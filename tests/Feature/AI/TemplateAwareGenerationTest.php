<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\PublishPromptVersion;
use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Enums\PromptCategory;
use App\Models\AiExecution;
use App\Models\FormatVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Models\PromptTemplate;
use App\Models\PromptVersion;
use App\Services\Documents\InstitutionalDocumentRenderer;
use App\Services\Documents\OfficeOpenXmlPackage;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class TemplateAwareGenerationTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesInstitutionalFormatScenario;

    public function test_formato_lleno_aporta_campo_custom_y_ejemplo_al_paquete_de_generacion(): void
    {
        [$request, $execution, $formatVersion, $packagePayload] = $this->waitingTemplateAwareExecution();

        $this->assertSame($formatVersion->id, $execution->format_version_id);
        $this->assertSame($formatVersion->id, $request->format_version_id);
        $this->assertSame($formatVersion->id, $packagePayload['format_context']['format_version_id']);
        $this->assertSame('filled_example', $packagePayload['format_context']['source_content_mode']);

        $field = collect($packagePayload['format_context']['custom_fields'])
            ->first(fn (array $item): bool => $item['label'] === 'Vinculación con las familias');
        $this->assertIsArray($field);
        $this->assertSame('ai', $field['source']);
        $this->assertTrue($field['required']);
        $this->assertSame(
            'Las familias participan en una actividad breve relacionada con el proyecto.',
            $field['example'],
        );

        $key = $field['key'];
        $this->assertContains($key, $packagePayload['output']['schema']['properties']['custom']['required']);
        $this->assertArrayHasKey($key, $packagePayload['output']['schema']['properties']['custom']['properties']);
        $this->assertStringContainsString('Vinculación con las familias', $packagePayload['prompt']['rendered']);
        $this->assertStringContainsString('Las familias participan en una actividad breve', $packagePayload['prompt']['rendered']);
    }

    public function test_resultado_custom_se_conserva_en_canonico_y_llena_el_word_original(): void
    {
        [$request, $execution, $formatVersion, $packagePayload] = $this->waitingTemplateAwareExecution(withAuditPrompt: true);
        $field = collect($packagePayload['format_context']['custom_fields'])
            ->first(fn (array $item): bool => $item['label'] === 'Vinculación con las familias');
        $this->assertIsArray($field);

        $generated = $this->generatedDraftFor($request->fresh(['currentInputVersion']));
        $generated['custom'] = [
            $field['key'] => 'Las familias participarán en una búsqueda de objetos del hogar vinculados con el tema.',
        ];

        $version = app(ImportManualGenerationResult::class)->execute($execution, $generated);
        $this->assertSame(
            'Las familias participarán en una búsqueda de objetos del hogar vinculados con el tema.',
            $version->content['custom'][$field['key']],
        );

        [$docx] = app(InstitutionalDocumentRenderer::class)->render($version, $formatVersion);
        $xml = OfficeOpenXmlPackage::fromBytes($docx->bytes)->get('word/document.xml');
        $this->assertStringContainsString(
            'Las familias participarán en una búsqueda de objetos del hogar vinculados con el tema.',
            $xml,
        );
        $this->assertStringNotContainsString(
            'Las familias participan en una actividad breve relacionada con el proyecto.',
            $xml,
        );
    }

    /** @return array{0:PlanningRequest,1:AiExecution,2:FormatVersion,3:array<string,mixed>} */
    private function waitingTemplateAwareExecution(bool $withAuditPrompt = false): array
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
        if ($withAuditPrompt) {
            $this->publishAuditPrompt();
        }

        $execution = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '91919191-9191-4919-8919-919191919191',
        );
        $event = OutboxEvent::query()->where('aggregate_id', $request->id)->sole();
        app(ProcessOutboxEvent::class)->execute($event);

        $execution = $execution->fresh(['manualPackage']);
        $request = $request->fresh();
        $package = $execution->manualPackage;
        $this->assertNotNull($package);
        $payload = json_decode(
            Storage::disk($package->disk)->get($package->path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [$request, $execution, $formatVersion, $payload];
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

    private function publishAuditPrompt(): PromptVersion
    {
        $template = PromptTemplate::factory()->create([
            'key' => 'planning.audit',
            'category' => PromptCategory::Audit->value,
        ]);
        $schema = json_decode(
            file_get_contents(resource_path('schemas/ai/audit_result_v1.schema.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $version = PromptVersion::factory()->create([
            'template_id' => $template->id,
            'body' => 'CANONICAL={{canonical_plan}} OUTPUT={{output_schema}}',
            'allowed_variables' => ['canonical_plan', 'output_schema'],
            'output_schema' => $schema,
            'schema_version' => 'audit_result_v1',
        ]);

        return app(PublishPromptVersion::class)->execute($this->admin(), $version);
    }
}
