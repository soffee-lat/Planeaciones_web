<?php

namespace Tests\Feature;

use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OfficialPrimaryCurriculumPreflightCommandTest extends TestCase
{
    use RefreshDatabase;

    private function officialDraft(array $versionOverrides = []): CurriculumVersion
    {
        $curriculum = Curriculum::create([
            'code' => 'MX-NEM-PRIMARIA',
            'name' => 'Nueva Escuela Mexicana · Primaria',
            'country_code' => 'MX',
            'educational_level' => 'Primaria',
            'selectable_version_id' => null,
        ]);

        return CurriculumVersion::create(array_merge([
            'curriculum_id' => $curriculum->id,
            'number' => 1,
            'label' => 'Borrador oficial de prueba',
            'source_reference' => 'pendiente',
            'published_at' => null,
            'published_by' => null,
            'checksum' => null,
        ], $versionOverrides));
    }

    public function test_without_version_lists_official_versions_without_mutating_them(): void
    {
        $version = $this->officialDraft();

        $this->artisan('curriculum:preflight-official-primary')
            ->assertExitCode(0);

        $version->refresh();
        $this->assertNull($version->published_at);
        $this->assertNull($version->published_by);
        $this->assertNull($version->checksum);
        $this->assertNull($version->curriculum->fresh()->selectable_version_id);
    }

    public function test_preflight_failure_does_not_publish_or_select_version(): void
    {
        $version = $this->officialDraft();

        $this->artisan('curriculum:preflight-official-primary', ['version' => $version->id])
            ->expectsOutput('Preflight fallido: OFFICIAL_PRIMARY_SOURCE_DOMAIN_REQUIRED')
            ->expectsOutput('No se publicó ni modificó ninguna versión curricular.')
            ->assertExitCode(1);

        $version->refresh();
        $this->assertNull($version->published_at);
        $this->assertNull($version->published_by);
        $this->assertNull($version->checksum);
        $this->assertNull($version->curriculum->fresh()->selectable_version_id);
    }

    public function test_published_version_is_audited_instead_of_rejected_as_already_published(): void
    {
        $version = $this->officialDraft();
        $publisher = User::factory()->create();

        $version->forceFill([
            'published_at' => now(),
            'published_by' => $publisher->id,
            'checksum' => str_repeat('a', 64),
        ])->save();

        $curriculum = $version->curriculum()->firstOrFail();
        $curriculum->forceFill(['selectable_version_id' => $version->id])->save();

        $this->artisan('curriculum:preflight-official-primary', ['version' => $version->id])
            ->expectsOutput('Preflight fallido: OFFICIAL_PRIMARY_SOURCE_DOMAIN_REQUIRED')
            ->doesntExpectOutput('Preflight fallido: OFFICIAL_PRIMARY_VERSION_ALREADY_PUBLISHED')
            ->assertExitCode(1);

        $version->refresh();
        $this->assertNotNull($version->published_at);
        $this->assertSame(str_repeat('a', 64), $version->checksum);
        $this->assertSame($version->id, $curriculum->fresh()->selectable_version_id);
    }

    public function test_unknown_version_fails_cleanly(): void
    {
        $this->artisan('curriculum:preflight-official-primary', ['version' => 999999])
            ->expectsOutput('CurriculumVersion no encontrado.')
            ->assertExitCode(1);
    }

    public function test_non_numeric_version_is_rejected(): void
    {
        $this->artisan('curriculum:preflight-official-primary', ['version' => 'abc'])
            ->expectsOutput('El argumento version debe ser un ID numérico positivo de CurriculumVersion.')
            ->assertExitCode(1);
    }
}
