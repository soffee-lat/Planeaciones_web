<?php

namespace Tests\Feature\Commerce;

use App\Enums\PlanningRequestStatus;
use App\Filament\App\Pages\MyPlan;
use App\Filament\App\Resources\PlanningRequests\Pages\EditPlanningRequest;
use App\Filament\App\Resources\PlanningRequests\Pages\ViewPlanningRequest;
use App\Services\Commerce\PlanningCommercialPresentation;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningCommercialUiTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    protected function setUp(): void
    {
        parent::setUp();
        Filament::setCurrentPanel(Filament::getPanel('app'));
    }

    public function test_filament_confirmation_without_rights_commits_snapshot_then_retry_uses_new_plan(): void
    {
        $request = $this->draft();
        $this->actingAs($request->owner);

        Livewire::test(EditPlanningRequest::class, ['record' => $request->id])->callAction('confirm')->assertHasNoErrors();

        $this->assertSame(PlanningRequestStatus::ESPERANDO_PAGO, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->input_snapshot);
        $this->assertSame(1, $request->inputVersions()->count());
        $this->assertDatabaseCount('usage_reservations', 0);
        $period = $this->period($request);
        Livewire::test(ViewPlanningRequest::class, ['record' => $request->id])->callAction('activateProcessing')->assertHasNoErrors();
        $this->assertSame($period->id, $request->fresh()->subscription_period_id);
        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->fresh()->status);
    }

    public function test_filament_confirm_immediately_reserves_and_shows_friendly_state(): void
    {
        $request = $this->draft();
        $this->period($request);
        $this->actingAs($request->owner);
        Livewire::test(EditPlanningRequest::class, ['record' => $request->id])->callAction('confirm')->assertHasNoErrors();
        $this->get('/app/planning-requests/'.$request->id)->assertOk()->assertSee('Preparando')->assertDontSee('LISTA_PARA_PROCESAR')->assertDontSee('operation_key');
        $this->assertSame(1, $request->usageReservations()->count());
    }

    public function test_confirm_saves_visible_dates_before_freezing_and_reserving(): void
    {
        $request = $this->draft();
        $this->period($request);
        $this->actingAs($request->owner);
        Livewire::test(EditPlanningRequest::class, ['record' => $request->id])
            ->fillForm(['ends_on' => '2026-10-08'])->callAction('confirm')->assertHasNoFormErrors();
        $fresh = $request->fresh();
        $this->assertSame(2, $fresh->planning_units);
        $this->assertSame('2026-10-08', $fresh->input_snapshot['request']['ends_on']);
    }

    public function test_admin_can_read_commercial_snapshot_but_has_no_edit_route(): void
    {
        $request = $this->draft();
        $this->period($request);
        $this->confirm($request);
        $this->authorize($request);
        $this->actingAs($this->admin());
        Filament::setCurrentPanel(Filament::getPanel('admin'));
        $this->get('/admin/planning-requests/'.$request->id)->assertOk()->assertSee('Solo lectura')->assertSee('calendar_days_v1');
        $this->get('/admin/planning-requests/'.$request->id.'/edit')->assertNotFound();
        $this->assertSame(4, $request->fresh()->planning_units);
    }

    public function test_manipulated_action_data_cannot_select_foreign_period_version_units_or_key(): void
    {
        $request = $this->draft();
        $own = $this->period($request);
        $other = $this->draft();
        $foreign = $this->period($other);
        $this->confirm($request);
        $this->actingAs($request->owner);

        Livewire::test(ViewPlanningRequest::class, ['record' => $request->id])
            ->callAction('activateProcessing', data: [
                'subscription_period_id' => $foreign->id, 'plan_version_id' => $foreign->plan_version_id,
                'planning_units' => 1, 'available' => 9999, 'operation_key' => 'attacker-key',
            ])->assertHasNoErrors();

        $fresh = $request->fresh();
        $this->assertSame($own->id, $fresh->subscription_period_id);
        $this->assertSame($own->plan_version_id, $fresh->plan_version_id);
        $this->assertSame(4, $fresh->planning_units);
        $this->assertDatabaseMissing('usage_reservations', ['operation_key' => 'attacker-key']);
        $this->assertSame(0, $foreign->reservations()->count());
    }

    public function test_other_customers_and_reviewer_cannot_open_request_or_my_plan(): void
    {
        $request = $this->confirm($this->draft());
        $this->actingAs($this->customer())->get('/app/planning-requests/'.$request->id)->assertNotFound();
        $this->actingAs($this->reviewer())->get('/app/mi-plan')->assertForbidden();
        $this->get('/app/planning-requests/'.$request->id)->assertForbidden();
        auth()->logout();
        $this->get('/app/mi-plan')->assertRedirect('/app/login');
    }

    public function test_my_plan_only_reads_own_balances_and_escapes_plan_name(): void
    {
        $request = $this->draft();
        $period = $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);
        $period->planVersion->plan->update(['name' => '<script>alert(1)</script> Mi plan ficticio']);
        $this->confirm($request);
        $this->authorize($request);
        $other = $this->draft();
        $foreign = $this->period($other);
        $foreign->planVersion->plan->update(['name' => 'Plan privado de otra cuenta']);
        $this->actingAs($request->owner);

        $this->get('/app/mi-plan')->assertOk()->assertSee('4 reservadas')->assertSee('4 disponibles')
            ->assertSee('Revisión humana incluida')->assertSee('&lt;script&gt;', false)
            ->assertDontSee('<script>alert(1)</script>', false)->assertDontSee('Plan privado de otra cuenta')
            ->assertDontSee('operation_key')->assertDontSee('subscription_period_id')->assertDontSee('payment_metadata');
        $this->assertSame(2, $period->reservations()->count());
    }

    public function test_final_review_preview_shows_current_requirements_without_writes_and_no_plan_message(): void
    {
        $request = $this->draft();
        $request->forceFill([
            'curriculum_confirmed_at' => now(),
            'curriculum_selection_fingerprint' => str_repeat('a', 64),
        ])->save();

        $this->actingAs($request->owner);

        $this->get(route('planning.review', $request))
            ->assertOk()
            ->assertSee('No tienes un plan activo');

        $period = $this->period($request, ['human_review_required' => true, 'human_review_limit' => 8]);
        $preview = app(PlanningCommercialPresentation::class)->forCustomer($request->owner, '2026-10-01', '2026-10-28');
        $this->assertSame(28, $preview['days']);
        $this->assertSame(4, $preview['units']);

        $this->get(route('planning.review', $request))
            ->assertOk()
            ->assertSee('4 unidades necesarias')
            ->assertSee('8 disponibles')
            ->assertSee('Revisión humana incluida');

        $this->assertSame(0, $period->reservations()->count());
        $this->assertNull($request->fresh()->calculation_snapshot);
    }
}
