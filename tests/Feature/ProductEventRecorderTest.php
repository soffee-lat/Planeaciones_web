<?php

namespace Tests\Feature;

use App\Enums\ProductEventType;
use App\Models\PlanningRequest;
use App\Services\Analytics\ProductEventRecorder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductEventRecorderTest extends PedagogyTestCase
{
    public function test_registra_contexto_sin_copiar_contenido_pedagogico(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->draftFor($ctx);

        $event = app(ProductEventRecorder::class)->record(
            $ctx['user'],
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: [
                'entry_surface' => 'curricular_validation_v1',
                'profile_reused' => true,
                'session_minutes_known' => true,
            ],
        );

        $this->assertSame($ctx['user']->id, $event->user_id);
        $this->assertSame($request->id, $event->planning_request_id);
        $this->assertSame($ctx['group']->id, $event->group_id);
        $this->assertSame($ctx['version']->id, $event->curriculum_version_id);
        $this->assertSame($ctx['grade']->id, $event->grade_id);
        $this->assertSame(ProductEventType::PlanningStarted, $event->event_type);
        $this->assertSame('curricular_validation_v1', $event->metadata['entry_surface']);
        $this->assertArrayNotHasKey('project', $event->metadata);
        $this->assertArrayNotHasKey('topic', $event->metadata);
    }

    public function test_planning_started_accepts_structured_period_metadata(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->draftFor($ctx);

        $event = app(ProductEventRecorder::class)->record(
            $ctx['user'],
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: [
                'entry_surface' => 'curricular_validation_v1',
                'profile_reused' => true,
                'session_minutes_known' => true,
                'period_type' => 'month',
                'structured_topics' => true,
            ],
        );

        $this->assertSame('month', $event->metadata['period_type']);
        $this->assertTrue($event->metadata['structured_topics']);
    }

    public function test_planning_started_rejects_invalid_structured_period_metadata(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->draftFor($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PRODUCT_EVENT_PERIOD_TYPE_INVALID');

        app(ProductEventRecorder::class)->record(
            $ctx['user'],
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: [
                'period_type' => 'quarter',
                'structured_topics' => true,
            ],
        );
    }

    public function test_no_permite_registrar_evento_sobre_solicitud_ajena(): void
    {
        $a = $this->seedFullTeacher();
        $b = $this->seedFullTeacher();
        $requestB = $this->draftFor($b);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('PRODUCT_EVENT_REQUEST_OWNER_MISMATCH');

        app(ProductEventRecorder::class)->record(
            $a['user'],
            ProductEventType::PlanningStarted,
            request: $requestB,
        );
    }

    public function test_metadata_no_permitida_se_rechaza_en_lugar_de_guardar_datos_sensibles(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->draftFor($ctx);

        try {
            app(ProductEventRecorder::class)->record(
                $ctx['user'],
                ProductEventType::PlanningStarted,
                request: $request,
                metadata: [
                    'entry_surface' => 'curricular_validation_v1',
                    'student_name' => 'Dato que nunca debe persistirse',
                    'diagnosis' => 'Dato sensible',
                ],
            );
            $this->fail('Se esperaba rechazo de metadata no autorizada.');
        } catch (\RuntimeException $e) {
            $this->assertStringStartsWith('PRODUCT_EVENT_METADATA_NOT_ALLOWED:', $e->getMessage());
        }

        $this->assertDatabaseCount('product_events', 0);
    }

    public function test_postgresql_impide_insertar_contexto_de_otro_propietario(): void
    {
        $a = $this->seedFullTeacher();
        $b = $this->seedFullTeacher();
        $requestB = $this->draftFor($b);

        $this->expectException(QueryException::class);

        DB::table('product_events')->insert([
            'user_id' => $a['user']->id,
            'planning_request_id' => $requestB->id,
            'group_id' => $requestB->group_id,
            'curriculum_version_id' => $requestB->curriculum_version_id,
            'grade_id' => $requestB->grade_id,
            'event_type' => ProductEventType::PlanningStarted->value,
            'metadata' => json_encode([], JSON_THROW_ON_ERROR),
            'occurred_at' => now(),
        ]);
    }

    public function test_eventos_son_append_only_en_postgresql(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->draftFor($ctx);
        $event = app(ProductEventRecorder::class)->record(
            $ctx['user'],
            ProductEventType::PlanningStarted,
            request: $request,
            metadata: ['entry_surface' => 'curricular_validation_v1'],
        );

        $this->expectException(QueryException::class);

        DB::table('product_events')
            ->where('id', $event->id)
            ->update(['event_type' => ProductEventType::PlanningCompleted->value]);
    }

    private function draftFor(array $ctx): PlanningRequest
    {
        return PlanningRequest::factory()->create([
            'owner_id' => $ctx['user']->id,
            'group_id' => $ctx['group']->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
            'creation_mode' => 'quick',
        ]);
    }
}
