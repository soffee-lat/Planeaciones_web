<?php

namespace Tests\Feature;

use App\Models\PlanningRequest;

class GroupDeletionPolicyTest extends PedagogyTestCase
{
    public function test_group_without_planning_history_can_be_deleted(): void
    {
        $scene = $this->seedFullTeacher();

        $this->assertTrue($scene['user']->can('delete', $scene['group']));
    }

    public function test_group_with_planning_history_cannot_be_deleted_and_must_be_archived(): void
    {
        $scene = $this->seedFullTeacher();

        PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
        ]);

        $this->assertFalse($scene['user']->can('delete', $scene['group']->fresh()));
        $this->assertTrue($scene['user']->can('archive', $scene['group']->fresh()));
    }
}
