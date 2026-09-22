<?php

namespace Tests\Feature;

use App\Actions\Documents\AnalyzeInstitutionalFormatVersion;
use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Documents\PublishFormatVersion;
use App\Actions\Documents\RenderInstitutionalFormatSample;
use App\Actions\Documents\ReviewInstitutionalFormatSample;
use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Actions\Planning\StartPlanningExperiment;
use App\Enums\InstitutionalFormatKind;
use App\Filament\App\Pages\StartPlanning;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use App\Services\Documents\PlanningFormatResolver;
use Livewire\Livewire;
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

    public function test_new_planning_defaults_to_general_export_format(): void
    {
        $scene = $this->seedFullTeacher();
        $this->actingAs($scene['user']);
        $standard = app(EnsureStandardFormat::class)->execute()['version'];

        Livewire::test(StartPlanning::class)
            ->assertSet('format_version_id', $standard->id)
            ->assertSee('Formato del documento')
            ->assertSee('El formato general de Planeaciones queda seleccionado por defecto.');
    }

    public function test_start_planning_persists_explicit_owned_export_format(): void
    {
        $scene = $this->seedFullTeacher();
        $scene['profile']->fill([
            'student_count' => 25,
            'general_level' => 'medio',
            'session_minutes' => 50,
            'characteristics' => 'Grupo activo.',
        ])->save();

        $institutional = $this->publishedInstitutionalFormat($scene['user']->id);

        $request = app(StartPlanningExperiment::class)->execute(
            $scene['user'],
            $scene['group']->id,
            '2026-09-21',
            '2026-09-25',
            'Conociendo mi entorno',
            formatVersionId: $institutional->id,
        );

        $this->assertSame($institutional->id, $request->format_version_id);
        $this->assertSame(
            $institutional->id,
            app(PlanningFormatResolver::class)->resolve($request)->id,
        );
    }

    public function test_export_default_preserves_explicit_format_selected_during_creation(): void
    {
        $scene = $this->seedFullTeacher();
        $this->actingAs($scene['user']);
        $institutional = $this->publishedInstitutionalFormat($scene['user']->id);

        $request = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'creation_mode' => 'quick',
            'format_version_id' => $institutional->id,
        ]);

        $this->assertSame(
            $institutional->id,
            PlanningRequestResource::exportFormatVersionIdFor($request),
        );

        $request->forceFill(['format_version_id' => null])->save();
        $standard = app(EnsureStandardFormat::class)->execute()['version'];

        $this->assertSame(
            $standard->id,
            PlanningRequestResource::exportFormatVersionIdFor($request->fresh()),
        );
    }

    public function test_filled_planning_example_is_analysis_only_and_falls_back_to_standard(): void
    {
        $scene = $this->seedFullTeacher();
        $this->actingAs($scene['user']);

        $draft = $this->institutionalDraftFromBytes(
            $scene['user']->id,
            $this->institutionalFilledTemplateBytes(),
        );
        $exampleVersion = app(AnalyzeInstitutionalFormatVersion::class)->execute(
            $draft['version'],
            $scene['user'],
        );
        $this->assertSame(
            'filled_example',
            data_get($exampleVersion->validation_report, 'analysis.source_content_mode'),
        );

        $sample = app(RenderInstitutionalFormatSample::class)->execute($exampleVersion, $scene['user']);
        app(ReviewInstitutionalFormatSample::class)->approve(
            $sample,
            $scene['user'],
            'El archivo se conserva únicamente como ejemplo de análisis.',
        );
        $exampleVersion = app(PublishFormatVersion::class)->execute(
            $exampleVersion->fresh(),
            $scene['user'],
        );

        $options = PlanningRequestResource::formatVersionOptions();
        $this->assertArrayNotHasKey($exampleVersion->id, $options);

        $request = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'creation_mode' => 'quick',
            'format_version_id' => $exampleVersion->id,
        ]);

        $standard = app(EnsureStandardFormat::class)->execute()['version'];
        $resolved = app(PlanningFormatResolver::class)->resolve($request);

        $this->assertSame($standard->id, $resolved->id);
        $this->assertSame(InstitutionalFormatKind::Standard, $resolved->format->kind);
    }

    public function test_default_export_is_standard_even_when_group_has_institutional_preference(): void
    {
        $scene = $this->seedFullTeacher();
        $institutional = $this->publishedInstitutionalFormat($scene['user']->id);
        $profile = $scene['profile']->fresh();
        $revision = (int) $profile->revision;

        app(UpdateGroupProfile::class)->execute($scene['user'], $profile, [
            'preferred_format_id' => $institutional->format_id,
        ]);

        $this->assertSame($revision, (int) $profile->fresh()->revision);

        $standard = app(EnsureStandardFormat::class)->execute()['version'];
        $request = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'creation_mode' => 'quick',
            'format_version_id' => null,
        ]);

        $resolved = app(PlanningFormatResolver::class)->resolve($request);

        $this->assertSame($standard->id, $resolved->id);
        $this->assertSame(InstitutionalFormatKind::Standard, $resolved->format->kind);
        $this->assertSame('standard-v1', $resolved->renderer);
    }

    public function test_explicit_export_choice_can_use_owned_institutional_format(): void
    {
        $scene = $this->seedFullTeacher();
        $institutional = $this->publishedInstitutionalFormat($scene['user']->id);
        $request = PlanningRequest::factory()->create([
            'owner_id' => $scene['user']->id,
            'group_id' => $scene['group']->id,
            'curriculum_version_id' => $scene['version']->id,
            'grade_id' => $scene['grade']->id,
            'creation_mode' => 'quick',
            'format_version_id' => $institutional->id,
        ]);

        $resolved = app(PlanningFormatResolver::class)->resolve($request);

        $this->assertSame($institutional->id, $resolved->id);
        $this->assertSame('institutional-v1', $resolved->renderer);
    }
}
