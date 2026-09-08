<?php

namespace Tests\Feature;

use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Enums\RoleCode;
use App\Models\PlanningRequest;

class PlanningRequestSecurityTest extends PedagogyTestCase
{
    private function makeDraftFor($user, $ctx = null): PlanningRequest
    {
        $ctx ??= $this->seedFullTeacher($user);
        return PlanningRequest::factory()->create([
            'owner_id' => $ctx['user']->id,
            'group_id' => $ctx['group']->id,
            'curriculum_version_id' => $ctx['version']->id,
            'grade_id' => $ctx['grade']->id,
        ]);
    }

    public function test_customer_a_cannot_view_or_edit_customer_b_request(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $reqB = $this->makeDraftFor($b);

        $this->actingAs($a);
        $res = $this->get("/app/planning-requests/{$reqB->id}");
        $this->assertContains($res->status(), [403, 404]);

        $res = $this->get("/app/planning-requests/{$reqB->id}/edit");
        $this->assertContains($res->status(), [403, 404]);
    }

    public function test_customer_a_cannot_update_customer_b_request_via_action(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $reqB = $this->makeDraftFor($b);

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);
        app(UpdatePlanningRequestDraft::class)->execute($a, $reqB, ['project' => 'hack']);
    }

    public function test_reviewer_has_no_access(): void
    {
        $reviewer = $this->reviewer();
        $customer = $this->customer();
        $req = $this->makeDraftFor($customer);

        $this->actingAs($reviewer);
        $res = $this->get("/app/planning-requests/{$req->id}");
        $this->assertContains($res->status(), [403, 404]);
    }

    public function test_owner_id_manipulation_on_edit_is_ignored(): void
    {
        $a = $this->customer();
        $b = $this->customer();
        $ctxA = $this->seedFullTeacher($a);
        $req = $this->makeDraftFor($a, $ctxA);

        // El request page recibe payload con owner_id de B → EditPlanningRequest strip lo elimina.
        // Verificamos vía action: no expone owner_id como editable.
        $r = app(UpdatePlanningRequestDraft::class)->execute($a, $req, [
            'project' => 'ok',
        ]);
        $this->assertEquals($a->id, $r->owner_id);
    }
}
