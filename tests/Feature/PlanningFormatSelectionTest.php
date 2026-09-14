<?php

namespace Tests\Feature;

use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\Documents\PlanningFormatResolver;
use Tests\Concerns\CreatesInstitutionalFormatScenario;

class PlanningFormatSelectionTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_export_options_include_owned_institutional_and_global_standard_but_not_foreign(): void
    {
        $scene = $this->seedFullTeacher();
        $this->actingAs($scene['user']);

        $owned = $this->publishedInstitutionalFormat($scene['user']->id);
        $foreign = $this->publishedInstitutionalFormat($this->customer()->id);
        $standard = app(EnsureStandardFormat::class)->execute()['version'];

        $options = PlanningRequestResource::formatVersionOptions();

        $this->assertArrayHasKey($owned->id, $options);
        $this->assertArrayHasKey($standard->id, $options);
        $this->assertArrayNotHasKey($foreign->id, $options);
        $this->assertStringContainsString('institucional', $options[$owned->id]);
        $this->assertStringContainsString('estándar', $options[$standard->id]);
    }

    public function test_confirmation_does_not_require_output_format_and_snapshot_stays_format_agnostic(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo listo.',
        ])->save();

        // La existencia de formatos institucionales no debe bloquear la generación.
        $this->publishedInstitutionalFormat($scene['user']->id);

        $request = $this->requestFor($scene, [
            'starts_on' => '2026-09-15',
            'ends_on' => '2026-09-19',
            'project' => 'Independencia de México',
            'format_version_id' => null,
        ]);
        [$content, $pda] = $this->firstContentAndPda($scene);
        app(SyncPlanningRequestSelections::class)->execute($scene['user'], $request, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);

        $confirmed = app(ConfirmPlanningRequest::class)->execute($scene['user'], $request->refresh());

        $this->assertNull($confirmed->format_version_id);
        $this->assertArrayNotHasKey('format_version_id', $confirmed->input_snapshot['request']);
    }

    public function test_default_export_is_standard_even_when_group_has_institutional_preference(): void
    {
        $scene = $this->seedFullTeacher();
        $institutional = $this->publishedInstitutionalFormat($scene['user']->id);
        $scene['profile']->forceFill(['preferred_format_id' => $institutional->format_id])->save();
        $standard = app(EnsureStandardFormat::class)->execute()['version'];
        $request = $this->requestFor($scene, ['format_version_id' => null]);

        $resolved = app(PlanningFormatResolver::class)->resolve($request);

        $this->assertSame($standard->id, $resolved->id);
        $this->assertSame('standard-v1', $resolved->renderer);
    }

    public function test_explicit_export_choice_can_still_use_owned_institutional_format(): void
    {
        $scene = $this->seedFullTeacher();
        $institutional = $this->publishedInstitutionalFormat($scene['user']->id);
        $request = $this->requestFor($scene, ['format_version_id' => $institutional->id]);

        $resolved = app(PlanningFormatResolver::class)->resolve($request);

        $this->assertSame($institutional->id, $resolved->id);
        $this->assertSame('institutional-v1', $resolved->renderer);
    }

    /** @return array{0:CurricularContent,1:Pda} */
    private function firstContentAndPda(array $scene): array
    {
        $content = CurricularContent::query()
            ->where('curriculum_version_id', $scene['version']->id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $scene['grade']->id))
            ->firstOrFail();
        $pda = Pda::query()
            ->where('curricular_content_id', $content->id)
            ->where('grade_id', $scene['grade']->id)
            ->firstOrFail();

        return [$content, $pda];
    }

    private function requestFor(array $scene, array $overrides = []): PlanningRequest
    {
        return PlanningRequest::factory()->create(array_merge([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'creation_mode' => 'quick',
        ], $overrides));
    }
}
