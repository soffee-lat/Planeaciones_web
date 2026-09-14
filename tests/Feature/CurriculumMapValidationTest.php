<?php

namespace Tests\Feature;

use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Planning\StartPlanningExperiment;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Enums\ProductEventType;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\Pda;
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
        $this->get($url)->assertOk()->assertSee('Estas son las conexiones que encontramos');
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

    public function test_blank_optional_form_values_do_not_invalidate_confirmed_map(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $service->state($ctx['user'], $request);
        $service->acceptAllSuggested($ctx['user'], $request);
        $confirmed = $service->confirm($ctx['user'], $request);

        $revision = (int) $confirmed->input_revision;
        $fingerprint = (string) $confirmed->curriculum_selection_fingerprint;

        app(UpdatePlanningRequestDraft::class)->execute($ctx['user'], $confirmed, [
            'period_label' => '',
            'book_pages' => '',
            'required_activities' => '',
            'special_events' => '',
            'comments' => '',
            'pedagogical_notes' => '',
            'suggested_initial_assessment' => '',
            'requested_assessment' => '',
        ]);

        $confirmed->refresh();
        $this->assertTrue($confirmed->hasConfirmedCurriculumMap());
        $this->assertSame($revision, (int) $confirmed->input_revision);
        $this->assertSame($fingerprint, $confirmed->curriculum_selection_fingerprint);
        $this->assertNull($confirmed->book_pages);
        $this->assertNull($confirmed->required_activities);
        $this->assertNull($confirmed->special_events);
        $this->assertNull($confirmed->comments);
    }

    public function test_batch_catalog_add_accepts_multiple_selections_in_one_request(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $service = app(CurriculumMapService::class);
        $state = $service->state($ctx['user'], $request, false);
        $pdaId = (int) array_key_first($state['catalog']['pdas']);
        $pda = Pda::query()->findOrFail($pdaId);

        $this->actingAs($ctx['user']);
        $response = $this->post(route('planning.curriculum-map.add', $request), [
            'pda_ids' => [$pdaId],
        ]);

        $response->assertRedirect();
        $after = $service->state($ctx['user'], $request, false);
        $this->assertContains($pdaId, $after['selected']['pdas']);
        $this->assertContains((int) $pda->curricular_content_id, $after['selected']['contents']);
    }

    public function test_http_confirm_skips_duplicate_edit_wizard_and_goes_to_tracking_view(): void
    {
        $ctx = $this->seedFullTeacher();
        $request = $this->startRequest($ctx);
        $this->actingAs($ctx['user']);

        $this->get(route('planning.curriculum-map', $request))->assertOk();
        $this->post(route('planning.curriculum-map.accept-all', $request))->assertRedirect();
        $response = $this->post(route('planning.curriculum-map.confirm', $request));

        $request->refresh();
        $this->assertTrue($request->hasConfirmedCurriculumMap());
        $this->assertTrue($request->isConfirmed());
        $response->assertRedirect(PlanningRequestResource::getUrl('view', ['record' => $request->id]));
    }

    /** @param array<string,mixed> $ctx */
    private function startRequest(array $ctx): PlanningRequest
    {
        $format = app(EnsureStandardFormat::class)->execute()['version'];

        return app(StartPlanningExperiment::class)->execute(
            $ctx['user'],
            $ctx['group']->id,
            $format->id,
            now()->addDay()->format('Y-m-d'),
            now()->addDays(5)->format('Y-m-d'),
            'Contenido demo',
            'Trabajar contenido de ejemplo con el grupo.',
        );
    }
}
