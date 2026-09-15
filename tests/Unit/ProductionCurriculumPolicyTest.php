<?php

namespace Tests\Unit;

use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Services\Curriculum\ProductionCurriculumPolicy;
use Illuminate\Support\Carbon;
use RuntimeException;
use Tests\TestCase;

class ProductionCurriculumPolicyTest extends TestCase
{
    public function test_it_rejects_published_demo_curriculum(): void
    {
        $version = $this->version([
            'code' => 'DEMO',
            'name' => 'Currículo DEMO Datos ficticios, sin validez curricular.',
            'country_code' => 'DEMO',
            'educational_level' => 'demo',
        ], 'DEMO-1');

        $policy = new ProductionCurriculumPolicy();

        $this->assertFalse($policy->isPlanningEligible($version));
    }

    public function test_it_accepts_published_non_demo_curriculum_identity(): void
    {
        $version = $this->version([
            'code' => 'MX-NEM-PRIMARIA',
            'name' => 'Nueva Escuela Mexicana · Primaria',
            'country_code' => 'MX',
            'educational_level' => 'primaria',
        ], 'SEP 2024 · Fases 3-5');

        $policy = new ProductionCurriculumPolicy();

        $this->assertTrue($policy->isPlanningEligible($version));
    }

    public function test_it_rejects_demo_snapshot_before_generation(): void
    {
        $policy = new ProductionCurriculumPolicy();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(ProductionCurriculumPolicy::NOT_READY);

        $policy->assertSnapshotEligible([
            'curriculum' => [
                'curriculum' => [
                    'code' => 'DEMO',
                    'name' => 'Currículo DEMO',
                    'country_code' => 'DEMO',
                    'educational_level' => 'demo',
                ],
                'version' => [
                    'label' => 'DEMO-1',
                    'checksum' => str_repeat('a', 64),
                    'published_at' => '2026-01-01T00:00:00+00:00',
                ],
                'phase' => ['name' => 'Fase demo A'],
                'grade' => ['name' => 'Grado demo A1'],
                'formative_fields' => [],
                'contents' => [],
                'pdas' => [],
                'axes' => [],
            ],
        ]);
    }

    public function test_it_accepts_democracia_in_a_production_snapshot(): void
    {
        $policy = new ProductionCurriculumPolicy();

        $policy->assertSnapshotEligible([
            'curriculum' => [
                'curriculum' => [
                    'code' => 'MX-NEM-PRIMARIA',
                    'name' => 'Educación Primaria — Nueva Escuela Mexicana',
                    'country_code' => 'MX',
                    'educational_level' => 'primaria',
                ],
                'version' => [
                    'label' => 'SEP 2024 · Fases 3-5',
                    'source_reference' => 'https://educacionbasica.sep.gob.mx/',
                    'checksum' => str_repeat('a', 64),
                    'published_at' => '2026-01-01T00:00:00+00:00',
                ],
                'phase' => ['name' => 'Fase 3'],
                'grade' => ['name' => 'Primer grado'],
                'formative_fields' => [],
                'contents' => [[
                    'code' => 'F3-ENS-C012',
                    'title' => 'Democracia como forma de vida',
                    'full_text' => 'Construcción participativa de normas para una convivencia democrática.',
                ]],
                'pdas' => [],
                'axes' => [],
            ],
        ]);

        $this->addToAssertionCount(1);
    }

    private function version(array $curriculumData, string $label): CurriculumVersion
    {
        $curriculum = new Curriculum($curriculumData);
        $curriculum->id = 10;

        $version = new CurriculumVersion([
            'number' => 1,
            'label' => $label,
            'checksum' => str_repeat('b', 64),
            'source_reference' => 'https://educacionbasica.sep.gob.mx/',
        ]);
        $version->id = 20;
        $version->published_at = Carbon::parse('2026-01-01 00:00:00');
        $version->setRelation('curriculum', $curriculum);

        return $version;
    }
}
