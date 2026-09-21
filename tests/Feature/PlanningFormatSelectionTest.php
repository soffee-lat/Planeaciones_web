<?php

namespace Tests\Feature;

use App\Actions\Documents\EnsureStandardFormat;
use App\Actions\Pedagogy\UpdateGroupProfile;
use App\Enums\InstitutionalFormatKind;
use App\Enums\InstitutionalFormatStatus;
use App\Filament\App\Resources\PlanningRequests\PlanningRequestResource;
use App\Models\PlanningRequest;
use App\Models\FormatVersion;
use App\Models\InstitutionalFormat;
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

    public function test_global_institutional_analysis_example_is_hidden_and_falls_back_to_standard(): void
    {
        $scene = $this->seedFullTeacher();
        $this->actingAs($scene['user']);

        $example = InstitutionalFormat::factory()->create([
            'owner_id' => null,
            'kind' => InstitutionalFormatKind::Institutional->value,
            'status' => InstitutionalFormatStatus::Ready->value,
            'name' => 'Ejemplo de análisis',
        ]);
        $exampleVersion = FormatVersion::factory()->create([
            'format_id' => $example->id,
            'renderer' => 'institutional-v1',
            'published_at' => now(),
        ]);

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
