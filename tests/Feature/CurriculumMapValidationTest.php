<?php

namespace Tests\Feature;

use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Actions\Schedules\SaveGroupSchedule;
use App\Enums\ProductEventType;
use App\Models\PlanningRequest;
use App\Models\ProductEvent;
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

    public function test_http_accept_all_and_confirm_route_reaches_existing_summary_flow(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $this->actingAs($ctx['user']);

        $this->get(route('planning.curriculum-map', $request))->assertOk();
        $this->post(route('planning.curriculum-map.accept-all', $request))->assertRedirect();
        $response = $this->post(route('planning.curriculum-map.confirm', $request));

        $response->assertRedirect();
        $request->refresh();
        $this->assertTrue($request->hasConfirmedCurriculumMap());
        $this->assertStringContainsString('/app/planning-requests/', $response->headers->get('Location'));
    }

    public function test_schedule_coverage_explains_exact_missing_field_and_filters_catalog(): void
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

        $this->actingAs($ctx['user']);
        $this->get(route('planning.curriculum-map', $request))
            ->assertOk()
            ->assertSee('Faltantes para completar tu horario')
            ->assertSee('Ver opciones de FF-1')
            ->assertSee('data-field-code="FF-1"', false);

        $contentId = (int) $state['suggestion']['content_ids'][0];
        $pdaId = (int) $state['suggestion']['pda_ids'][0];

        $service->decide($ctx['user'], $request, 'content', $contentId, true);
        $afterContent = $service->state($ctx['user'], $request, false);
        $this->assertSame('pda', $afterContent['schedule_field_coverage']['missing'][0]['missing_requirement']);

        $service->decide($ctx['user'], $request, 'pda', $pdaId, true);
        $afterPda = $service->state($ctx['user'], $request, false);
        $this->assertSame([], $afterPda['schedule_field_coverage']['missing']);
        $this->assertTrue($afterPda['schedule_field_coverage']['required'][0]['covered']);
    }

    /** @param array<string,mixed> $ctx */
    private function startRequest(array $ctx): PlanningRequest
    {
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
