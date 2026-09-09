<?php

namespace Tests\Unit;

use App\Exceptions\AiContractException;
use App\Services\AI\CanonicalPlanValidator;
use App\Services\AI\GeneratedPlanDraftValidator;
use App\Services\AI\JsonSchemaSubsetValidator;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

class GeneratedPlanDraftValidatorTest extends TestCase
{
    private function validator(): GeneratedPlanDraftValidator
    {
        return new GeneratedPlanDraftValidator(new JsonSchemaSubsetValidator());
    }

    /** @return array<string,mixed> */
    private function validDraft(): array
    {
        $activity = [
            'instruction' => 'Instrucción DEMO',
            'teacher_action' => 'Acompaña.',
            'student_action' => 'Participa.',
            'organization' => 'whole_group',
            'materials' => [],
            'expected_evidence' => ['Evidencia'],
            'assessment_checks' => ['Criterio observable'],
        ];

        return [
            'contract_version' => 'generated_plan_draft_v1',
            'title' => 'DEMO',
            'project_name' => null,
            'purpose' => 'Propósito DEMO',
            'problem_or_interest' => null,
            'scenario' => null,
            'learning_goals' => ['Meta DEMO'],
            'methodology' => ['name' => 'Secuencia didáctica', 'rationale' => 'DEMO', 'phases' => []],
            'transversal_connections' => [],
            'sessions' => [[
                'id' => 'S01',
                'sequence' => 1,
                'date' => null,
                'title' => 'Sesión 1',
                'estimated_minutes' => 50,
                'methodology_phase' => null,
                'specific_goal' => 'Meta específica DEMO',
                'field_codes' => ['FF-1'],
                'content_codes' => ['CT-A'],
                'pda_codes' => ['PDA-A'],
                'axis_codes' => [],
                'moments' => [
                    ['type' => 'inicio', 'minutes' => 10, 'activities' => [$activity]],
                    ['type' => 'desarrollo', 'minutes' => 30, 'activities' => [$activity]],
                    ['type' => 'cierre', 'minutes' => 10, 'activities' => [$activity]],
                ],
                'formative_assessment' => [
                    'criteria' => ['Criterio'],
                    'evidence' => ['Evidencia'],
                    'instrument_ids' => ['I01'],
                    'feedback_strategy' => 'Retroalimentación DEMO',
                ],
                'differentiation' => ['support' => [], 'challenge' => [], 'accessibility' => []],
                'homework_or_extension' => null,
                'teacher_notes' => null,
            ]],
            'assessment_plan' => [
                'approach' => 'formative',
                'diagnostic' => null,
                'ongoing' => 'Seguimiento DEMO',
                'closure' => 'Cierre DEMO',
                'instruments' => [[
                    'id' => 'I01',
                    'type' => 'checklist',
                    'name' => 'Lista DEMO',
                    'purpose' => 'Prueba',
                    'criteria' => ['Criterio'],
                    'applies_to_sessions' => ['S01'],
                    'scale' => null,
                ]],
            ],
            'resources' => ['physical_materials' => [], 'digital_resources' => [], 'provided_references' => []],
            'adaptation_notes' => ['based_on_group_profile' => [], 'assumptions' => [], 'missing_information' => []],
        ];
    }

    #[Test]
    public function los_schemas_y_el_ejemplo_canonical_son_json_validos(): void
    {
        foreach ([
            resource_path('schemas/ai/generated_plan_draft_v1.schema.json'),
            resource_path('schemas/ai/canonical_plan_v1.schema.json'),
            resource_path('schemas/ai/examples/canonical_plan_v1.example.json'),
        ] as $path) {
            $decoded = json_decode(file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
            $this->assertIsArray($decoded);
        }

        $canonical = json_decode(file_get_contents(resource_path('schemas/ai/examples/canonical_plan_v1.example.json')), true, 512, JSON_THROW_ON_ERROR);
        $validated = (new CanonicalPlanValidator(new JsonSchemaSubsetValidator()))->validate($canonical);
        $this->assertSame('canonical_plan_v1', $validated->schemaVersion());
    }

    #[Test]
    public function acepta_un_draft_v1_valido(): void
    {
        $draft = $this->validator()->validate($this->validDraft());
        $this->assertSame('generated_plan_draft_v1', $draft->contractVersion());
        $this->assertCount(1, $draft->sessions());
    }

    #[Test]
    public function rechaza_version_de_contrato_incorrecta(): void
    {
        $payload = $this->validDraft();
        $payload['contract_version'] = 'v999';
        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('AI_SCHEMA_CONST_MISMATCH');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_campo_requerido_faltante(): void
    {
        $payload = $this->validDraft();
        unset($payload['purpose']);
        $this->expectExceptionMessage('AI_SCHEMA_REQUIRED');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_propiedad_inesperada_incluyendo_texto_curricular_inventado(): void
    {
        $payload = $this->validDraft();
        $payload['sessions'][0]['official_pda_text'] = 'Texto inventado por proveedor';
        $this->expectExceptionMessage('AI_SCHEMA_UNEXPECTED_PROPERTY');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_ids_de_sesion_duplicados(): void
    {
        $payload = $this->validDraft();
        $payload['sessions'][] = $payload['sessions'][0];
        $payload['sessions'][1]['sequence'] = 2;
        $this->expectExceptionMessage('GENERATED_SESSION_ID_DUPLICATE');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_secuencia_con_huecos(): void
    {
        $payload = $this->validDraft();
        $payload['sessions'][0]['sequence'] = 2;
        $this->expectExceptionMessage('GENERATED_SESSION_SEQUENCE_INVALID');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_minutos_que_no_suman_la_duracion(): void
    {
        $payload = $this->validDraft();
        $payload['sessions'][0]['moments'][1]['minutes'] = 29;
        $this->expectExceptionMessage('GENERATED_SESSION_MINUTES_MISMATCH');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_momentos_fuera_de_inicio_desarrollo_cierre(): void
    {
        $payload = $this->validDraft();
        $payload['sessions'][0]['moments'][1]['type'] = 'inicio';
        $this->expectExceptionMessage('GENERATED_SESSION_MOMENTS_INVALID');
        $this->validator()->validate($payload);
    }

    #[Test]
    public function rechaza_instrumento_referenciado_inexistente(): void
    {
        $payload = $this->validDraft();
        $payload['sessions'][0]['formative_assessment']['instrument_ids'] = ['I99'];
        $this->expectExceptionMessage('GENERATED_SESSION_INSTRUMENT_NOT_FOUND');
        $this->validator()->validate($payload);
    }
}
