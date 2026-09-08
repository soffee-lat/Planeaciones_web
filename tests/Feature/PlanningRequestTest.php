<?php

namespace Tests\Feature;

use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Enums\PlanningRequestStatus;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class PlanningRequestTest extends PedagogyTestCase
{
    private function makeDraft(): array
    {
        $scene = $this->seedFullTeacher();
        $req = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
        ]);
        return ['scene' => $scene, 'req' => $req];
    }

    public function test_creates_valid_draft_owned_by_customer(): void
    {
        ['req' => $r, 'scene' => $s] = $this->makeDraft();
        $this->assertEquals(PlanningRequestStatus::BORRADOR, $r->status);
        $this->assertEquals($s['user']->id, $r->owner_id);
        $this->assertEquals($s['group']->id, $r->group_id);
        $this->assertEquals(0, $r->input_revision);
        $this->assertNull($r->input_snapshot);
    }

    public function test_group_of_another_owner_is_rejected_by_composite_fk(): void
    {
        $scene = $this->seedFullTeacher();
        $other = $this->customer();
        $this->expectException(QueryException::class);
        PlanningRequest::factory()->create([
            'owner_id' => $other->id, // does not own the group
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
        ]);
    }

    public function test_grade_from_another_version_is_rejected_by_composite_fk(): void
    {
        $scene = $this->seedFullTeacher();
        // draftGrade is from an unpublished version
        $this->expectException(QueryException::class);
        PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => \App\Models\Grade::query()
                ->where('curriculum_version_id', '!=', $scene['version']->id)
                ->firstOrFail()->id,
        ]);
    }

    public function test_draft_is_editable_and_no_op_save_does_not_bump_revision(): void
    {
        ['req' => $r] = $this->makeDraft();
        $r = app(UpdatePlanningRequestDraft::class)->execute(
            $r->owner,
            $r,
            [
                'project' => 'Nuevo proyecto',
                'topic' => $r->topic,
            ],
        );
        $this->assertEquals(1, $r->input_revision);
        $this->assertEquals('Nuevo proyecto', $r->project);

        // Segundo save sin cambios reales — no bump.
        $r2 = app(UpdatePlanningRequestDraft::class)->execute(
            $r->owner,
            $r,
            ['project' => 'Nuevo proyecto'],
        );
        $this->assertEquals(1, $r2->input_revision);
    }

    public function test_selection_of_valid_content_and_pda_syncs_and_bumps_revisions(): void
    {
        ['req' => $r, 'scene' => $s] = $this->makeDraft();
        $content = CurricularContent::query()->where('curriculum_version_id', $s['version']->id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $s['grade']->id))
            ->firstOrFail();
        $pda = Pda::query()->where('curricular_content_id', $content->id)
            ->where('grade_id', $s['grade']->id)->firstOrFail();

        $r = app(SyncPlanningRequestSelections::class)->execute($r->owner, $r, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);
        $this->assertEquals(1, $r->selection_revision);
        $this->assertEquals(1, $r->input_revision);
        $this->assertCount(1, $r->contents);
        $this->assertCount(1, $r->pdas);
    }

    public function test_pda_of_another_grade_is_rejected_by_action(): void
    {
        ['req' => $r, 'scene' => $s] = $this->makeDraft();
        $wrongPda = Pda::query()->where('curriculum_version_id', $s['version']->id)
            ->where('grade_id', $s['otherGrade']->id)->firstOrFail();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SyncPlanningRequestSelections::class)->execute($r->owner, $r, [
            'pdas' => [$wrongPda->id],
        ]);
    }

    public function test_content_of_another_version_is_rejected_by_action(): void
    {
        ['req' => $r, 'scene' => $s] = $this->makeDraft();
        $draftContent = CurricularContent::factory()->create([
            'curriculum_version_id' => $s['draftVersion']->id,
            'educational_phase_id' => \App\Models\EducationalPhase::query()->where('curriculum_version_id', $s['draftVersion']->id)->firstOrFail()->id,
            'formative_field_id' => \App\Models\FormativeField::factory()->create(['curriculum_version_id' => $s['draftVersion']->id])->id,
        ]);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        app(SyncPlanningRequestSelections::class)->execute($r->owner, $r, [
            'contents' => [$draftContent->id],
        ]);
    }

    public function test_pivot_composite_fk_blocks_manual_insertion_of_wrong_version(): void
    {
        ['req' => $r, 'scene' => $s] = $this->makeDraft();
        $otherContent = CurricularContent::factory()->create([
            'curriculum_version_id' => $s['draftVersion']->id,
            'educational_phase_id' => \App\Models\EducationalPhase::query()->where('curriculum_version_id', $s['draftVersion']->id)->firstOrFail()->id,
            'formative_field_id' => \App\Models\FormativeField::factory()->create(['curriculum_version_id' => $s['draftVersion']->id])->id,
        ]);
        // Bypass action: intentar meter fila directa con versión distinta a la de la solicitud → composite FK debe rechazar.
        $this->expectException(QueryException::class);
        DB::table('request_curricular_contents')->insert([
            'request_id' => $r->id,
            'curriculum_version_id' => $r->curriculum_version_id,
            'curricular_content_id' => $otherContent->id,
        ]);
    }
}
