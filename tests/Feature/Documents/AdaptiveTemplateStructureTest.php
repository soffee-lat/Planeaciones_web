<?php

namespace Tests\Feature\Documents;

use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Services\AI\FormatAwareGenerationSchema;
use App\Services\Documents\InstitutionalTemplateContract;
use App\Services\Documents\OfficeOpenXmlPackage;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesInstitutionalFormatScenario;
use Tests\Feature\PedagogyTestCase;

class AdaptiveTemplateStructureTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_analisis_expone_filas_y_tablas_como_zonas_estructurales(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id, [], true);

        $analysis = $version->validation_report['analysis'];
        $this->assertSame('visual_structures_v1', $analysis['mapping_strategy']);
        $this->assertGreaterThanOrEqual(2, $analysis['structural_zone_count']);

        $row = collect($analysis['structural_zones'])->firstWhere('kind', 'row');
        $table = collect($analysis['structural_zones'])->firstWhere('kind', 'table');

        $this->assertNotNull($row);
        $this->assertNotNull($table);
        $this->assertSame(['c:0', 'c:1'], $row['child_zone_ids']);
        $this->assertContains($row['id'], $table['child_row_ids']);
    }

    public function test_editor_guarda_fila_repetible_y_renderer_clona_ooxml_original(): void
    {
        $owner = $this->customer();
        $version = $this->analyzedInstitutional($owner->id, [], true);
        $row = collect($version->validation_report['analysis']['structural_zones'])->firstWhere('kind', 'row');
        $this->assertNotNull($row);

        $this->actingAs($owner)
            ->postJson(route('institutional-formats.structure-binding', $version->format_id), [
                'zone_id' => $row['id'],
                'mode' => 'save',
                'kind' => 'repeat_row',
                'label' => 'Actividades del formato',
                'instruction' => 'Genera una fila por cada actividad necesaria.',
                'fields' => [
                    ['zone_id' => $row['child_zone_ids'][0], 'mode' => 'manual'],
                    [
                        'zone_id' => $row['child_zone_ids'][1],
                        'mode' => 'ai',
                        'label' => 'Actividad',
                        'type' => 'long_text',
                        'instruction' => 'Describe la actividad correspondiente a esta fila.',
                        'required' => true,
                    ],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('ok', true);

        $fresh = $version->fresh();
        $this->assertSame(3, $fresh->mapping['schema_version']);
        $this->assertCount(1, $fresh->mapping['structures']);
        $structure = array_values($fresh->mapping['structures'])[0];
        $this->assertSame('repeat_row', $structure['kind']);
        $this->assertSame($row['id'], $structure['zone_id']);
        $this->assertStringStartsWith('custom.', $structure['field_path']);

        $fieldKey = substr($structure['field_path'], strlen('custom.'));
        $this->assertSame('repeating_block', $fresh->mapping['custom_fields'][$fieldKey]['type']);
        $this->assertSame('Actividad', array_values($fresh->mapping['custom_fields'][$fieldKey]['item_fields'])[0]['label']);

        $contract = app(InstitutionalTemplateContract::class)->build($fresh->loadMissing('sourceFile'), $fresh->mapping);
        $this->assertSame(2, $contract['schema_version']);
        $this->assertCount(1, $contract['structures']);
        $this->assertSame('Actividades del formato', $contract['structures'][0]['label']);

        $sample = app(RenderInstitutionalFormatSample::class)->execute($fresh, $owner);
        $bytes = Storage::disk('private')->get($sample->docxFile->path);
        $xml = OfficeOpenXmlPackage::fromBytes($bytes)->get('word/document.xml');

        $this->assertSame(2, substr_count($xml, 'Propósito:'));
        $this->assertStringContainsString('MUESTRA 1 · Actividad', $xml);
        $this->assertStringContainsString('MUESTRA 2 · Actividad', $xml);
    }

    public function test_schema_dinamico_cierra_objetos_de_bloque_repetible(): void
    {
        $base = [
            'type' => 'object',
            'required' => [],
            'properties' => [],
        ];
        $context = [
            'custom_fields' => [[
                'key' => 'actividades',
                'type' => 'repeating_block',
                'source' => 'ai',
                'required' => true,
                'item_fields' => [
                    'actividad' => [
                        'label' => 'Actividad',
                        'type' => 'long_text',
                        'required' => true,
                    ],
                    'fecha' => [
                        'label' => 'Fecha',
                        'type' => 'date',
                        'required' => false,
                    ],
                ],
            ]],
        ];

        $schema = app(FormatAwareGenerationSchema::class)->extend($base, $context);
        $items = $schema['properties']['custom']['properties']['actividades']['items'];

        $this->assertFalse($items['additionalProperties']);
        $this->assertSame(['actividad'], $items['required']);
        $this->assertSame('string', $items['properties']['actividad']['type']);
        $this->assertSame('date', $items['properties']['fecha']['format']);
    }
}
