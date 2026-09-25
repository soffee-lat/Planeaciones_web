<?php

namespace Tests\Feature\Admin;

use App\Actions\Commerce\EnsureInternalUnlimitedPlan;
use App\Actions\Commerce\GrantInternalUnlimitedMembership;
use App\Actions\Commerce\RevokeInternalUnlimitedMembership;
use App\Enums\PlanningRequestStatus;
use App\Filament\Resources\Users\UserResource;
use App\Services\Commerce\CurrentCommercialRights;
use App\Services\Commerce\PlanningCommercialPresentation;
use Filament\Facades\Filament;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class AdminUserManagementTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    public function test_admin_can_open_user_management_and_customer_cannot(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $this->actingAs($admin);
        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->get(UserResource::getUrl('index'))
            ->assertOk()
            ->assertSee('Usuarios')
            ->assertSee($customer->email);

        $this->actingAs($customer);

        $this->get('/admin/users')->assertForbidden();
    }

    public function test_admin_can_grant_internal_unlimited_membership(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        $period = app(GrantInternalUnlimitedMembership::class)->execute($admin, $customer);

        $this->assertSame(EnsureInternalUnlimitedPlan::PLAN_CODE, $period->planVersion->plan->code);
        $this->assertTrue($period->isReservable());
        $this->assertGreaterThan(999000, $period->entitlement('planning_limit'));

        $summary = app(PlanningCommercialPresentation::class)->forCustomer($customer);

        $this->assertTrue($summary['has_plan']);
        $this->assertTrue($summary['is_unlimited']);
        $this->assertSame('Membresía interna ilimitada', $summary['plan_name']);
    }

    public function test_internal_unlimited_membership_authorizes_real_planning_flow(): void
    {
        $request = $this->draft();
        $admin = $this->admin();

        app(GrantInternalUnlimitedMembership::class)->execute($admin, $request->owner);

        $request = $this->confirm($request);
        $request = $this->authorize($request);

        $this->assertSame(PlanningRequestStatus::LISTA_PARA_PROCESAR, $request->status);
        $this->assertNotNull($request->commercial_authorized_at);
        $this->assertSame(1, $request->usageReservations()->count());
        $this->assertSame(
            EnsureInternalUnlimitedPlan::PLAN_CODE,
            $request->subscription->plan->code,
        );
    }

    public function test_admin_can_revoke_internal_unlimited_membership(): void
    {
        $customer = $this->customer();
        $admin = $this->admin();

        app(GrantInternalUnlimitedMembership::class)->execute($admin, $customer);
        $this->assertNotNull(app(CurrentCommercialRights::class)->forCustomer($customer));

        app(RevokeInternalUnlimitedMembership::class)->execute($admin, $customer);

        $this->assertNull(app(CurrentCommercialRights::class)->forCustomer($customer));
    }

    public function test_unlimited_grant_does_not_replace_an_existing_commercial_subscription(): void
    {
        $request = $this->draft();
        $this->period($request);
        $admin = $this->admin();

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('INTERNAL_UNLIMITED_CONFLICTING_SUBSCRIPTION');

        app(GrantInternalUnlimitedMembership::class)->execute($admin, $request->owner);
    }
}
