<?php

namespace Tests\Feature\AI;

use App\Actions\AI\DispatchPlanningGeneration;
use App\Actions\AI\ImportManualAuditResult;
use App\Actions\AI\ImportManualCorrectionResult;
use App\Actions\AI\ImportManualGenerationResult;
use App\Actions\AI\ProcessOutboxEvent;
use App\Actions\AI\RouteAuditResult;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Enums\AiExecutionStage;
use App\Enums\PlanningRequestStatus;
use App\Models\AiExecution;
use App\Models\FormatVersion;
use App\Models\OutboxEvent;
use App\Models\PlanningRequest;
use App\Services\AI\AdaptiveCorrectionSchema;
use App\Services\AI\CanonicalPlanValidator;
use App\Services\AI\FormatAwareGenerationSchema;
use App\Services\Documents\InstitutionalDocumentRenderer;
use App\Services\Documents\OfficeOpenXmlPackage;
use App\Support\AI\CanonicalJson;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Feature\PedagogyTestCase;

class AdaptiveGenerationPipelineTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;
    use CreatesInstitutionalFormatScenario;
    use CreatesManualAiPipelineScenario;

    public function test_formato_v3_genera_canonico_adaptativo_sin_sessions_y_renderiza_estructura_repetible(): void
    {
        $scene = $this->waitingAdaptiveGeneration();

        $this->assertSame(
            FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
            $scene['package']['output']['schema']['properties']['contract_version']['const'],
        );
        $this->assertArrayNotHasKey('sessions', $scene['package']['output']['schema']['properties']);
        $this->assertSame(3, $scene['package']['format_context']['schema_version']);
        $this->assertSame(
            FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
            $scene['package']['format_context']['generation_contract'],
        );

        $payload = $this->adaptivePayload(
            $scene['request'],
            $scene['package'],
            $scene['custom_key'],
            $scene['item_key'],
            ['Actividad adaptativa uno.', 'Actividad adaptativa dos.'],
        );
        $version = app(ImportManualGenerationResult::class)->execute($scene['generation'], $payload);

        $this->assertSame(CanonicalPlanValidator::ADAPTIVE_SCHEMA_VERSION, $version->content['schema_version']);
        $this->assertArrayNotHasKey('sessions', $version->content);
        $this->assertSame(
            FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
            $version->content['source']['generation_contract_version'],
        );
        $this->assertCount(2, $version->content['custom'][$scene['custom_key']]);

        [$docx] = app(InstitutionalDocumentRenderer::class)->render($version, $scene['format_version']);
        $xml = OfficeOpenXmlPackage::fromBytes($docx->bytes)->get('word/document.xml');
        $this->assertStringContainsString('Actividad adaptativa uno.', $xml);
        $this->assertStringContainsString('Actividad adaptativa dos.', $xml);
    }

    public function test_audit_y_correction_adaptativos_corrigen_custom_sin_mutar_curriculo_ni_contexto(): void
    {
        $scene = $this->waitingAdaptiveGeneration();
        $source = app(ImportManualGenerationResult::class)->execute(
            $scene['generation'],
            $this->adaptivePayload(
                $scene['request'],
                $scene['package'],
                $scene['custom_key'],
                $scene['item_key'],
                ['Actividad que requiere mejora.'],
            ),
        );

        $audit = AiExecution::query()
            ->where('request_id', $scene['request']->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $audit->id . ':audit-dispatch')->sole(),
        );
        app(ImportManualAuditResult::class)->execute($audit->fresh(), [
            'schema_version' => 'audit_result_v1',
            'passed' => false,
            'findings' => [[
                'code' => 'LANGUAGE_QUALITY',
                'severity' => 'medium',
                'json_path' => '/custom/' . $scene['custom_key'],
                'explanation' => 'La actividad requiere mayor precisión didáctica.',
                'expected_correction' => 'Ajustar únicamente la actividad del formato.',
            ]],
        ]);
        $request = app(RouteAuditResult::class)->execute(
            $audit->fresh(),
            null,
            '92929292-9292-4929-8929-929292929292',
        );
        $this->assertSame(PlanningRequestStatus::CORRECCION_IA, $request->status);

        $correction = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Correction->value)
            ->sole();
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $correction->id . ':correction-dispatch')->sole(),
        );
        $correction = $correction->fresh(['manualPackage']);
        $this->assertNotNull($correction->manualPackage);
        $package = json_decode(
            Storage::disk($correction->manualPackage->disk)->get($correction->manualPackage->path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );
        $this->assertSame(AdaptiveCorrectionSchema::CONTRACT_VERSION, $package['output']['schema_version']);
        $this->assertSame(['custom'], $package['source']['section_keys']);

        $corrected = app(ImportManualCorrectionResult::class)->execute($correction, [
            'schema_version' => AdaptiveCorrectionSchema::CONTRACT_VERSION,
            'source_version_id' => $source->id,
            'patch' => [
                'custom' => [
                    $scene['custom_key'] => [[
                        $scene['item_key'] => 'Actividad corregida con instrucciones precisas.',
                    ]],
                ],
            ],
        ]);

        $this->assertSame(CanonicalPlanValidator::ADAPTIVE_SCHEMA_VERSION, $corrected->content['schema_version']);
        $this->assertSame($source->id, $corrected->parent_version_id);
        $this->assertSame(
            'Actividad corregida con instrucciones precisas.',
            $corrected->content['custom'][$scene['custom_key']][0][$scene['item_key']],
        );
        $this->assertArrayNotHasKey('sessions', $corrected->content);
        foreach (['source', 'context', 'curricular_alignment'] as $root) {
            $this->assertSame(
                CanonicalJson::hash($source->content[$root]),
                CanonicalJson::hash($corrected->content[$root]),
                $root,
            );
        }
        $this->assertSame(PlanningRequestStatus::AUDITORIA_IA, $request->fresh()->status);

        $reaudit = AiExecution::query()
            ->where('request_id', $request->id)
            ->where('stage', AiExecutionStage::Audit->value)
            ->where('input_manifest->source_version_id', $corrected->id)
            ->sole();
        $this->assertSame($corrected->content_hash, $reaudit->input_manifest['source_content_hash']);

        [$docx] = app(InstitutionalDocumentRenderer::class)->render($corrected, $scene['format_version']);
        $xml = OfficeOpenXmlPackage::fromBytes($docx->bytes)->get('word/document.xml');
        $this->assertStringContainsString('Actividad corregida con instrucciones precisas.', $xml);
        $this->assertStringNotContainsString('Actividad que requiere mejora.', $xml);
    }

    /** @return array{request:PlanningRequest,generation:AiExecution,format_version:FormatVersion,package:array<string,mixed>,custom_key:string,item_key:string} */
    private function waitingAdaptiveGeneration(): array
    {
        config([
            'ai.mode' => 'manual',
            'ai.manual.disk' => 'private',
            'ai.manual.prefix' => 'ai/adaptive-pipeline-test',
            'ai.internal_correction.max_rounds' => 2,
            'ai.internal_correction.max_known_cost' => null,
            'ai.internal_correction.cost_currency' => null,
        ]);

        $request = $this->draft();
        $owner = $request->owner;
        $version = $this->analyzedInstitutional($owner->id, [], true);
        $row = collect($version->validation_report['analysis']['structural_zones'])->firstWhere('kind', 'row');
        $this->assertNotNull($row);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.structure-binding', $version->format_id), [
                'zone_id' => $row['id'],
                'mode' => 'save',
                'kind' => 'repeat_row',
                'label' => 'Actividades del formato',
                'instruction' => 'Genera las actividades necesarias para este formato.',
                'fields' => [
                    ['zone_id' => $row['child_zone_ids'][0], 'mode' => 'manual'],
                    [
                        'zone_id' => $row['child_zone_ids'][1],
                        'mode' => 'ai',
                        'label' => 'Actividad',
                        'type' => 'long_text',
                        'instruction' => 'Describe una actividad concreta.',
                        'required' => true,
                    ],
                ],
            ])
            ->assertOk();

        $version = $version->fresh();
        $structure = array_values($version->mapping['structures'])[0];
        $customKey = substr((string) $structure['field_path'], strlen('custom.'));
        $itemKey = (string) array_key_first($version->mapping['custom_fields'][$customKey]['item_fields']);
        $this->assertNotSame('', $customKey);
        $this->assertNotSame('', $itemKey);

        $sample = app(RenderInstitutionalFormatSample::class)->execute($version, $owner);
        app(ReviewInstitutionalFormatSample::class)->approve($sample, $owner, 'El formato adaptativo se ve correctamente.');
        $version = app(PublishFormatVersion::class)->execute($version->fresh(), $owner);

        $profile = $request->group()->with('profile')->firstOrFail()->profile;
        app(UpdateGroupProfile::class)->execute($owner, $profile, [
            'preferred_format_id' => $version->format_id,
        ]);

        $this->period($request);
        $request = $this->authorize($this->confirm($request));
        $this->publishManualAiPrompts();

        $generation = app(DispatchPlanningGeneration::class)->execute(
            $request,
            '93939393-9393-4939-8939-939393939393',
        );
        app(ProcessOutboxEvent::class)->execute(
            OutboxEvent::query()->where('event_key', 'ai-execution:' . $generation->id . ':generation-dispatch')->sole(),
        );
        $generation = $generation->fresh(['manualPackage']);
        $this->assertNotNull($generation->manualPackage);
        $package = json_decode(
            Storage::disk($generation->manualPackage->disk)->get($generation->manualPackage->path),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        return [
            'request' => $request->fresh(['currentInputVersion']),
            'generation' => $generation,
            'format_version' => $version,
            'package' => $package,
            'custom_key' => $customKey,
            'item_key' => $itemKey,
        ];
    }

    /** @param array<string,mixed> $package @return array<string,mixed> */
    private function adaptivePayload(
        PlanningRequest $request,
        array $package,
        string $customKey,
        string $itemKey,
        array $activities,
    ): array {
        $request->loadMissing('currentInputVersion');
        $pdaCode = (string) data_get($request->currentInputVersion?->snapshot, 'curriculum.pdas.0.code', '');
        $this->assertNotSame('', $pdaCode);

        $payload = [
            'contract_version' => FormatAwareGenerationSchema::ADAPTIVE_CONTRACT_VERSION,
            'core' => [
                'title' => 'Planeación adaptativa de prueba',
                'purpose' => 'Desarrollar el PDA seleccionado mediante actividades pertinentes al grupo.',
                'learning_goals' => ['Aplicar el aprendizaje esperado en una situación concreta.'],
                'assessment_strategy' => 'Observación y evidencia producida durante las actividades.',
                'adaptation_considerations' => ['Ajustar apoyos de acuerdo con las necesidades del grupo.'],
                'pda_coverage' => [[
                    'pda_code' => $pdaCode,
                    'explanation' => 'Las actividades del formato trabajan explícitamente este PDA.',
                ]],
            ],
            'custom' => [
                $customKey => array_map(
                    static fn (string $activity): array => [$itemKey => $activity],
                    $activities,
                ),
            ],
        ];

        $schema = (array) data_get($package, 'output.schema', []);
        $required = array_values(array_map('strval', (array) ($schema['required'] ?? [])));
        if (in_array('fields', $required, true)) {
            $fieldSchemas = (array) data_get($schema, 'properties.fields.properties', []);
            $fields = [];
            foreach ($fieldSchemas as $path => $fieldSchema) {
                if (is_array($fieldSchema)) {
                    $fields[(string) $path] = $this->exampleForSchema($fieldSchema, (string) $path);
                }
            }
            $payload['fields'] = $fields;
        }

        return $payload;
    }

    /** @param array<string,mixed> $schema */
    private function exampleForSchema(array $schema, string $label): mixed
    {
        if (array_key_exists('const', $schema)) {
            return $schema['const'];
        }

        $type = $schema['type'] ?? 'string';
        if (is_array($type)) {
            $type = collect($type)->first(fn (mixed $candidate): bool => $candidate !== 'null') ?? 'string';
        }

        return match ($type) {
            'array' => [$this->exampleForSchema((array) ($schema['items'] ?? ['type' => 'string']), $label . ' item')],
            'object' => $this->exampleObjectForSchema($schema, $label),
            'integer' => max(1, (int) ($schema['minimum'] ?? 1)),
            'number' => max(1, (float) ($schema['minimum'] ?? 1)),
            'boolean' => true,
            default => ($schema['format'] ?? null) === 'date'
                ? '2026-10-01'
                : 'Valor adaptativo para ' . $label,
        };
    }

    /** @param array<string,mixed> $schema @return array<string,mixed> */
    private function exampleObjectForSchema(array $schema, string $label): array
    {
        $properties = (array) ($schema['properties'] ?? []);
        $required = array_values(array_map('strval', (array) ($schema['required'] ?? array_keys($properties))));
        $value = [];
        foreach ($required as $key) {
            $child = $properties[$key] ?? ['type' => 'string'];
            if (is_array($child)) {
                $value[$key] = $this->exampleForSchema($child, $label . '.' . $key);
            }
        }

        return $value;
    }
}
