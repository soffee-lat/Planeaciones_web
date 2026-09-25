<?php

namespace Tests\Feature;

use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Actions\Schedules\SaveGroupSchedule;
use App\Enums\PlanningRequestStatus;
use App\Enums\ProductEventType;
use App\Models\PlanningRequest;
use App\Models\ProductEvent;
use App\Filament\App\Resources\PlanningRequests\Pages\EditPlanningRequest;
use Livewire\Livewire;
use App\Services\Planning\CurriculumMapService;

class CurriculumMapValidationTest extends PedagogyTestCase
{
    public function test_map_page_is_owner_only_and_records_suggestions_once_per_fingerprint(): void
    {
        $ctx = $this->seedFullTeacher();
        $other = $this->customer();
        $request = $this->startRequest($ctx);

        $this->actingAs($ctx['user']);
        $url = route('planning.curriculum-map', $request);
        $this->get($url)->assertOk()->assertSee('Conexiones curriculares')->assertSee('Estas son las conexiones que encontramos');
        $this->get($url)->assertOk();

        $this->assertSame(1, ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::CurriculumSuggestionsShown->value)
            ->count());

        $this->actingAs($other);
        $this->get($url)->assertNotFound();
    }

    public function test_accept_all_then_confirm_persists_selection_fingerprint_and_event(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);

        $state = $service->state($ctx['user'], $request);
        $this->assertNotEmpty($state['suggestion']['content_ids']);
        $this->assertNotEmpty($state['suggestion']['pda_ids']);
        $this->assertGreaterThan(0, $state['pending_count']);

        $service->acceptAllSuggested($ctx['user'], $request);
        $afterAccept = $service->state($ctx['user'], $request, false);
        $this->assertSame(0, $afterAccept['pending_count']);
        $this->assertNotEmpty($afterAccept['selected']['contents']);
        $this->assertNotEmpty($afterAccept['selected']['pdas']);

        $confirmed = $service->confirm($ctx['user'], $request);
        $this->assertTrue($confirmed->isDraft());
        $this->assertTrue($confirmed->hasConfirmedCurriculumMap());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', (string) $confirmed->curriculum_selection_fingerprint);
        $this->assertNotNull($confirmed->curriculum_confirmed_at);
        $this->assertCount(count($afterAccept['selected']['contents']), $confirmed->contents);
        $this->assertCount(count($afterAccept['selected']['pdas']), $confirmed->pdas);

        $event = ProductEvent::query()
            ->where('planning_request_id', $request->id)
            ->where('event_type', ProductEventType::CurriculumMapConfirmed->value)
            ->latest('id')->firstOrFail();
        $this->assertSame($confirmed->curriculum_selection_fingerprint, $event->metadata['fingerprint']);
    }

    public function test_rejecting_a_suggestion_is_persisted_as_product_decision(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $state = $service->state($ctx['user'], $request);
        $contentId = (int) $state['suggestion']['content_ids'][0];

        $service->decide($ctx['user'], $request, 'content', $contentId, false);
        $after = $service->state($ctx['user'], $request, false);

        $this->assertSame('rejected', $after['statuses']['content'][$contentId]);
        $this->assertDatabaseHas('product_events', [
            'planning_request_id' => $request->id,
            'event_type' => ProductEventType::CurriculumSuggestionRejected->value,
        ]);
    }

    public function test_confirm_requires_explicit_resolution_of_pending_suggestions(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CURRICULUM_MAP_HAS_PENDING_DECISIONS');
        app(CurriculumMapService::class)->confirm($ctx['user'], $request);
    }

    public function test_selection_change_invalidates_a_previously_confirmed_map(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $service->state($ctx['user'], $request);
        $service->acceptAllSuggested($ctx['user'], $request);
        $confirmed = $service->confirm($ctx['user'], $request);
        $this->assertTrue($confirmed->hasConfirmedCurriculumMap());

        app(SyncPlanningRequestSelections::class)->execute($ctx['user'], $confirmed, [
            'contents' => [],
            'pdas' => [],
            'axes' => [],
        ]);

        $confirmed->refresh();
        $this->assertFalse($confirmed->hasConfirmedCurriculumMap());
        $this->assertNull($confirmed->curriculum_confirmed_at);
        $this->assertNull($confirmed->curriculum_selection_fingerprint);
    }

    public function test_changing_suggestion_input_invalidates_confirmed_map_but_dates_do_not(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $service->state($ctx['user'], $request);
        $service->acceptAllSuggested($ctx['user'], $request);
        $confirmed = $service->confirm($ctx['user'], $request);

        app(UpdatePlanningRequestDraft::class)->execute($ctx['user'], $confirmed, [
            'starts_on' => $confirmed->starts_on->copy()->addDay()->format('Y-m-d'),
        ]);
        $confirmed->refresh();
        $this->assertTrue($confirmed->hasConfirmedCurriculumMap());

        app(UpdatePlanningRequestDraft::class)->execute($ctx['user'], $confirmed, [
            'project' => 'Otro contenido demo',
        ]);
        $confirmed->refresh();
        $this->assertFalse($confirmed->hasConfirmedCurriculumMap());
    }

    public function test_http_accept_all_and_confirm_route_reaches_dedicated_review_flow(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $this->actingAs($ctx['user']);

        $this->get(route('planning.curriculum-map', $request))->assertOk();
        $this->post(route('planning.curriculum-map.accept-all', $request))->assertRedirect();
        $response = $this->post(route('planning.curriculum-map.confirm', $request));

        $response->assertRedirect(route('planning.review', $request));
        $request->refresh();
        $this->assertTrue($request->hasConfirmedCurriculumMap());

        $this->get(route('planning.review', $request))
            ->assertOk()
            ->assertSee('Resumen y confirmación')
            ->assertSee('Revisa tu planeación antes de confirmarla')
            ->assertSee('Confirmar planeación')
            ->assertDontSee('Grupo y modalidad')
            ->assertDontSee('Rápido · Te sugerimos alineación curricular');
    }

    public function test_legacy_edit_route_redirects_structured_request_to_current_stage(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $service->state($ctx['user'], $request);
        $service->acceptAllSuggested($ctx['user'], $request);
        $service->confirm($ctx['user'], $request);

        $this->actingAs($ctx['user']);

        Livewire::test(EditPlanningRequest::class, ['record' => $request->id])
            ->assertRedirect(route('planning.review', $request));
    }

    public function test_final_review_confirmation_freezes_snapshot_and_goes_to_tracking(): void
    {
        $ctx = $this->seedFullTeacher();
        $ctx['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo listo para planeación.',
        ])->save();

        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $service->state($ctx['user'], $request);
        $service->acceptAllSuggested($ctx['user'], $request);
        $service->confirm($ctx['user'], $request);

        $this->actingAs($ctx['user']);
        $response = $this->post(route('planning.review.confirm', $request));

        $request->refresh();
        $response->assertRedirect(
            \App\Filament\App\Resources\PlanningRequests\PlanningRequestResource::getUrl('view', ['record' => $request->id])
        );
        $this->assertSame(PlanningRequestStatus::ESPERANDO_PAGO, $request->status);
        $this->assertNotNull($request->input_snapshot);
        $this->assertNotNull($request->current_version_id);
        $this->assertTrue($request->hasConfirmedCurriculumMap());
    }

    public function test_schedule_coverage_explains_exact_missing_field_and_surfaces_actionable_options(): void
    {
        $ctx = $this->seedFullTeacher();

        app(SaveGroupSchedule::class)->execute($ctx['user'], $ctx['group'], [
            'day_starts_at' => '08:00',
            'day_ends_at' => '10:00',
            'blocks' => [[
                'day_of_week' => 1,
                'sequence' => 1,
                'starts_at' => '08:00',
                'ends_at' => '09:00',
                'label' => 'Campo oficial',
                'block_type' => 'class',
                'responsibility' => 'main_teacher',
                'include_in_planning' => true,
                'is_flexible' => false,
                'field_codes' => ['FF-1'],
                'notes' => null,
            ]],
        ]);

        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $state = $service->state($ctx['user'], $request);

        $this->assertSame('deterministic_v3_schedule_priority', $state['suggestion']['strategy_version']);
        $this->assertCount(1, $state['schedule_field_coverage']['missing']);
        $this->assertSame('FF-1', $state['schedule_field_coverage']['missing'][0]['code']);
        $this->assertSame('content_and_pda', $state['schedule_field_coverage']['missing'][0]['missing_requirement']);
        $this->assertArrayHasKey('FF-1', $state['schedule_field_options']);
        $this->assertNotEmpty($state['schedule_field_options']['FF-1']);

        $option = $state['schedule_field_options']['FF-1'][0];
        $this->assertSame('FF-1', $option['field_code']);
        $this->assertGreaterThan(0, $option['content_id']);
        $this->assertGreaterThan(0, $option['pda_id']);

        $this->actingAs($ctx['user']);
        $this->get(route('planning.curriculum-map', $request))
            ->assertOk()
            ->assertSee('Faltantes para completar tu horario')
            ->assertSee('Opciones para cubrir FF-1')
            ->assertSee('Seleccionar')
            ->assertSee('Agregar selecciones')
            ->assertSee('name="pda_ids[]"', false)
            ->assertSee((string) $option['pda_code']);

        $this->post(route('planning.curriculum-map.add', $request), [
            'pda_ids' => [$option['pda_id']],
        ])->assertRedirect();

        $after = $service->state($ctx['user'], $request, false);
        $this->assertSame([], $after['schedule_field_coverage']['missing']);
        $this->assertTrue($after['schedule_field_coverage']['required'][0]['covered']);
        $this->assertContains((int) $option['content_id'], $after['selected']['contents']);
        $this->assertContains((int) $option['pda_id'], $after['selected']['pdas']);
    }

    /** @param array<string,mixed> $ctx */
    private function startRequest(array $ctx): PlanningRequest
    {
        $this->addDefaultPlanningSchedule($ctx['user'], $ctx['group']);

        return app(StartPlanningExperiment::class)->execute(
            $ctx['user'],
            $ctx['group']->id,
            now()->addDay()->format('Y-m-d'),
            now()->addDays(5)->format('Y-m-d'),
            'Contenido demo',
            'Trabajar contenido de ejemplo con el grupo.',
        );
    }
}
