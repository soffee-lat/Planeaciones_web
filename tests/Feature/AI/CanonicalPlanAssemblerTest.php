<?php

namespace Tests\Feature\AI;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Data\Planning\GeneratedPlanDraft;
use App\Exceptions\AiContractException;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\FormativeField;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\AI\CanonicalPlanAssembler;
use App\Services\AI\GeneratedPlanDraftValidator;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class CanonicalPlanAssemblerTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;

    private function readyRequest(): PlanningRequest
    {
        $request = $this->draft(7);
        $this->period($request);
        $request = $this->confirm($request);

        return $this->authorize($request)->fresh(['currentInputVersion', 'usageReservations']);
    }

    private function validatedDraft(PlanningRequest $request, ?array $payload = null): GeneratedPlanDraft
    {
        return app(GeneratedPlanDraftValidator::class)->validate($payload ?? $this->generatedDraftFor($request));
    }

    public function test_no_ensambla_request_sin_autorizacion_comercial(): void
    {
        $request = $this->confirm($this->draft(7))->fresh(['currentInputVersion']);
        $draft = $this->validatedDraft($request);

        $this->expectExceptionMessage('CANONICAL_REQUEST_NOT_READY');
        app(CanonicalPlanAssembler::class)->assemble($request, $draft);
    }

    public function test_ensambla_canonical_desde_snapshot_y_draft_sin_cambiar_estado_ni_consumir(): void
    {
        $request = $this->readyRequest();
        $statusBefore = $request->status;
        $reservationsBefore = $request->usageReservations()->count();
        $snapshot = $request->currentInputVersion->snapshot;

        $canonical = app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request));
        $array = $canonical->toArray();

        $this->assertSame('canonical_plan_v1', $canonical->schemaVersion());
        $this->assertSame($request->id, $array['source']['planning_request_id']);
        $this->assertSame($snapshot['curriculum']['version']['checksum'], $array['source']['curriculum_checksum']);
        $this->assertSame($snapshot['curriculum']['contents'][0]['full_text'], $array['curricular_alignment']['contents'][0]['full_text']);
        $this->assertSame($snapshot['curriculum']['pdas'][0]['full_text'], $array['curricular_alignment']['pdas'][0]['full_text']);
        $this->assertSame($statusBefore, $request->fresh()->status);
        $this->assertSame($reservationsBefore, $request->usageReservations()->count());
        $this->assertSame(0, $request->usageReservations()->whereNotNull('consumed_at')->count());
    }

    public function test_datos_confirmados_y_referencias_del_docente_prevalecen_sobre_el_draft(): void
    {
        $request = $this->readyRequest();
        $payload = $this->generatedDraftFor($request);
        $payload['project_name'] = 'Proyecto inventado por proveedor';
        $payload['resources']['provided_references'] = ['Referencia inventada'];

        $canonical = app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload))->toArray();
        $snapshot = $request->currentInputVersion->snapshot;

        $this->assertSame($snapshot['request']['project'], $canonical['planning']['project_name']);
        $this->assertSame([$snapshot['request']['book_pages']], $canonical['resources']['provided_references']);
        $this->assertNotContains('Referencia inventada', $canonical['resources']['provided_references']);
    }

    public function test_rechaza_content_externo_al_snapshot(): void
    {
        $request = $this->readyRequest();
        $payload = $this->generatedDraftFor($request);
        $payload['sessions'][0]['content_codes'] = ['CT-EXTERNAL'];

        $this->expectException(AiContractException::class);
        $this->expectExceptionMessage('GENERATED_CONTENT_REFERENCE_NOT_ALLOWED');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload));
    }

    public function test_rechaza_pda_externo_al_snapshot(): void
    {
        $request = $this->readyRequest();
        $payload = $this->generatedDraftFor($request);
        $payload['sessions'][0]['pda_codes'] = ['PDA-EXTERNAL'];

        $this->expectExceptionMessage('GENERATED_PDA_REFERENCE_NOT_ALLOWED');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload));
    }

    public function test_rechaza_eje_externo_al_snapshot(): void
    {
        $request = $this->readyRequest();
        $payload = $this->generatedDraftFor($request);
        $payload['sessions'][0]['axis_codes'] = ['AX-EXTERNAL'];

        $this->expectExceptionMessage('GENERATED_AXIS_REFERENCE_NOT_ALLOWED');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload));
    }

    public function test_rechaza_field_externo_al_snapshot(): void
    {
        $request = $this->readyRequest();
        $payload = $this->generatedDraftFor($request);
        $payload['sessions'][0]['field_codes'] = ['FF-EXTERNAL'];

        $this->expectExceptionMessage('GENERATED_FIELD_REFERENCE_NOT_ALLOWED');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload));
    }

    public function test_rechaza_pda_content_mismatch(): void
    {
        $request = $this->readyRequest();
        $snapshot = $request->currentInputVersion->snapshot;
        $first = $snapshot['curriculum']['contents'][0];
        $second = $first;
        $second['id'] = 999999;
        $second['code'] = 'CT-SECOND';
        $second['title'] = 'Contenido ficticio segundo';
        $second['full_text'] = 'Texto ficticio segundo';
        $snapshot['curriculum']['contents'][] = $second;
        $snapshot['curriculum']['pdas'][0]['content_id'] = 999999;
        $request->input_snapshot = $snapshot;
        $request->currentInputVersion->snapshot = $snapshot;

        $payload = $this->generatedDraftFor($request);
        $payload['sessions'][0]['content_codes'] = [$first['code']];

        $this->expectExceptionMessage('GENERATED_PDA_CONTENT_MISMATCH');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload));
    }

    public function test_rechaza_pda_seleccionado_sin_cobertura(): void
    {
        $request = $this->readyRequest();
        $snapshot = $request->currentInputVersion->snapshot;
        $secondPda = $snapshot['curriculum']['pdas'][0];
        $secondPda['id'] = 999998;
        $secondPda['code'] = 'PDA-SECOND';
        $snapshot['curriculum']['pdas'][] = $secondPda;
        $request->input_snapshot = $snapshot;
        $request->currentInputVersion->snapshot = $snapshot;

        $this->expectExceptionMessage('GENERATED_PDA_NOT_COVERED');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request));
    }

    public function test_fecha_de_sesion_fuera_del_periodo_es_rechazada(): void
    {
        $request = $this->readyRequest();
        $payload = $this->generatedDraftFor($request);
        $payload['sessions'][0]['date'] = '2026-12-31';

        $this->expectExceptionMessage('GENERATED_SESSION_DATE_OUTSIDE_REQUEST');
        app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request, $payload));
    }

    public function test_nueva_version_curricular_publicada_no_reescribe_el_canonical_historico(): void
    {
        $request = $this->readyRequest();
        $oldChecksum = $request->currentInputVersion->snapshot['curriculum']['version']['checksum'];
        $curriculum = $request->curriculumVersion->curriculum;
        $draft = $curriculum->versions()->whereNull('published_at')->firstOrFail();
        $phase = $draft->phases()->firstOrFail();
        $grade = $draft->grades()->firstOrFail();
        $field = FormativeField::factory()->create(['curriculum_version_id' => $draft->id, 'code' => 'FF-NEW']);
        $content = CurricularContent::factory()->create([
            'curriculum_version_id' => $draft->id,
            'educational_phase_id' => $phase->id,
            'formative_field_id' => $field->id,
            'code' => 'CT-NEW',
            'title' => 'Contenido nuevo',
            'full_text' => 'Contenido posterior ficticio',
        ]);
        Pda::factory()->create([
            'curriculum_version_id' => $draft->id,
            'curricular_content_id' => $content->id,
            'grade_id' => $grade->id,
            'code' => 'PDA-NEW',
            'full_text' => 'PDA posterior ficticio',
        ]);
        ArticulatingAxis::factory()->create(['curriculum_version_id' => $draft->id, 'code' => 'AX-NEW']);
        $published = app(PublishCurriculumVersion::class)($draft, $this->admin());
        $curriculum->update(['selectable_version_id' => $published->id]);

        $canonical = app(CanonicalPlanAssembler::class)->assemble($request, $this->validatedDraft($request));

        $this->assertSame($oldChecksum, $canonical->toArray()['curricular_alignment']['curriculum']['checksum']);
        $this->assertNotSame($published->checksum, $oldChecksum);
    }
}
