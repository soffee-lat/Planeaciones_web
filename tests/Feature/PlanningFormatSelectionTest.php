<?php

namespace Tests\Feature;

use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Planning\ConfirmPlanningRequest;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Planning\UpdatePlanningRequestDraft;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\CurricularContent;
use App\Models\Pda;
use App\Models\PlanningRequest;
use App\Services\Documents\PlanningFormatGenerationContext;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\Concerns\CreatesInstitutionalFormatScenario;

class PlanningFormatSelectionTest extends PedagogyTestCase
{
    use CreatesInstitutionalFormatScenario;

    public function test_format_options_include_owned_institutional_and_global_standard_but_not_foreign(): void
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

    public function test_draft_persists_usable_format_and_tracks_input_revision(): void
    {
        $scene = $this->seedFullTeacher();
        $format = $this->publishedInstitutionalFormat($scene['user']->id);
        $request = $this->requestFor($scene);
        $before = (int) $request->input_revision;

        app(UpdatePlanningRequestDraft::class)->execute(
            $scene['user'],
            $request,
            ['format_version_id' => $format->id],
        );

        $request->refresh();
        $this->assertSame($format->id, $request->format_version_id);
        $this->assertSame($before + 1, (int) $request->input_revision);
    }

    public function test_draft_rejects_foreign_format_version(): void
    {
        $scene = $this->seedFullTeacher();
        $foreign = $this->publishedInstitutionalFormat($this->customer()->id);
        $request = $this->requestFor($scene);

        $this->expectException(ValidationException::class);

        app(UpdatePlanningRequestDraft::class)->execute(
            $scene['user'],
            $request,
            ['format_version_id' => $foreign->id],
        );
    }

    public function test_confirmation_requires_explicit_format_when_institutional_format_exists_without_group_preference(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo listo.',
        ])->save();

        $this->publishedInstitutionalFormat($scene['user']->id);

        $request = $this->requestFor($scene, [
            'starts_on' => '2026-09-15',
            'ends_on' => '2026-09-19',
            'project' => 'Independencia de México',
            'format_version_id' => null,
        ]);

        $content = CurricularContent::query()
            ->where('curriculum_version_id', $scene['version']->id)
            ->whereHas('pdas', fn ($q) => $q->where('grade_id', $scene['grade']->id))
            ->firstOrFail();
        $pda = Pda::query()
            ->where('curricular_content_id', $content->id)
            ->where('grade_id', $scene['grade']->id)
            ->firstOrFail();

        app(SyncPlanningRequestSelections::class)->execute($scene['user'], $request, [
            'contents' => [$content->id],
            'pdas' => [$pda->id],
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('PLANNING_REQUEST_FORMAT_REQUIRED');

        app(ConfirmPlanningRequest::class)->execute($scene['user'], $request->refresh());
    }

    public function test_selected_institutional_format_reaches_ai_generation_context(): void
    {
        $scene = $this->seedFullTeacher();
        $format = $this->publishedInstitutionalFormat($scene['user']->id);
        $request = $this->requestFor($scene, ['format_version_id' => $format->id]);

        $context = app(PlanningFormatGenerationContext::class)->build($request);

        $this->assertSame($format->id, $context['format_version_id']);
        $this->assertSame('institutional-v1', $context['renderer']);
        $this->assertIsArray($context['template_contract']);
        $this->assertNotEmpty($context['template_contract']);
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
