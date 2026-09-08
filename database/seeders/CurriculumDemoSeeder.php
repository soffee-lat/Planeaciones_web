<?php

namespace Database\Seeders;

use App\Actions\Curriculum\PublishCurriculumVersion;
use App\Enums\RoleCode;
use App\Models\ArticulatingAxis;
use App\Models\CurricularContent;
use App\Models\Curriculum;
use App\Models\CurriculumVersion;
use App\Models\EducationalPhase;
use App\Models\FormativeField;
use App\Models\Grade;
use App\Models\Pda;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;

class CurriculumDemoSeeder extends Seeder
{
    public const DEMO_NOTICE = 'Datos ficticios, sin validez curricular.';

    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \LogicException('CurriculumDemoSeeder solo se ejecuta en local/testing.');
        }

        $actor = $this->resolveAdministrator();

        $curriculum = Curriculum::firstOrCreate(
            ['code' => 'DEMO'],
            [
                'name' => 'Currículo DEMO ' . self::DEMO_NOTICE,
                'country_code' => 'DEMO',
                'educational_level' => 'demo',
                'description' => self::DEMO_NOTICE,
            ]
        );

        $v1 = $this->buildVersion($curriculum, 1, 'DEMO-1', textSuffix: 'v1');
        app(PublishCurriculumVersion::class)($v1, $actor);
        $curriculum->fill(['selectable_version_id' => $v1->id])->save();

        $this->buildVersion($curriculum, 2, 'DEMO-2', textSuffix: 'v2 (borrador)');
    }

    protected function resolveAdministrator(): User
    {
        $admin = User::whereHas('roles', fn ($q) => $q->where('code', RoleCode::Administrator->value))->first();
        if ($admin) {
            return $admin;
        }
        $admin = User::factory()->create([
            'name' => 'Admin DEMO',
            'email' => 'admin-demo-seed@example.test',
            'email_verified_at' => now(),
        ]);
        $role = Role::firstOrCreate(['code' => RoleCode::Administrator->value]);
        $admin->roles()->syncWithoutDetaching([$role->id]);

        return $admin;
    }

    protected function buildVersion(Curriculum $curriculum, int $number, string $label, string $textSuffix): CurriculumVersion
    {
        $version = CurriculumVersion::create([
            'curriculum_id' => $curriculum->id,
            'number' => $number,
            'label' => $label,
            'source_reference' => 'ficticio ' . $label,
        ]);

        $phaseA = EducationalPhase::create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-A',
            'name' => 'Fase demo A',
            'description' => self::DEMO_NOTICE,
            'sort_order' => 1,
        ]);
        $phaseB = EducationalPhase::create([
            'curriculum_version_id' => $version->id,
            'code' => 'PH-B',
            'name' => 'Fase demo B',
            'description' => self::DEMO_NOTICE,
            'sort_order' => 2,
        ]);

        $gradeA1 = $this->makeGrade($version, $phaseA, 'GR-A1', 'Grado demo A1', 1);
        $gradeA2 = $this->makeGrade($version, $phaseA, 'GR-A2', 'Grado demo A2', 2);
        $gradeB1 = $this->makeGrade($version, $phaseB, 'GR-B1', 'Grado demo B1', 3);
        $gradeB2 = $this->makeGrade($version, $phaseB, 'GR-B2', 'Grado demo B2', 4);

        $lang = FormativeField::create([
            'curriculum_version_id' => $version->id,
            'code' => 'FF-LANG',
            'name' => 'Campo demo Lenguaje',
            'description' => self::DEMO_NOTICE,
            'sort_order' => 1,
        ]);
        $math = FormativeField::create([
            'curriculum_version_id' => $version->id,
            'code' => 'FF-MATH',
            'name' => 'Campo demo Matemáticas',
            'description' => self::DEMO_NOTICE,
            'sort_order' => 2,
        ]);

        $contents = [
            'CT-A-LANG' => [$phaseA, $lang, [$gradeA1, $gradeA2]],
            'CT-A-MATH' => [$phaseA, $math, [$gradeA1, $gradeA2]],
            'CT-B-LANG' => [$phaseB, $lang, [$gradeB1, $gradeB2]],
            'CT-B-MATH' => [$phaseB, $math, [$gradeB1, $gradeB2]],
        ];

        foreach ($contents as $code => [$phase, $field, $grades]) {
            $content = CurricularContent::create([
                'curriculum_version_id' => $version->id,
                'educational_phase_id' => $phase->id,
                'formative_field_id' => $field->id,
                'code' => $code,
                'title' => 'Contenido demo ' . $code,
                'full_text' => "Contenido de ejemplo {$code} ({$textSuffix}). " . self::DEMO_NOTICE,
                'sort_order' => 1,
            ]);
            foreach ($grades as $i => $grade) {
                Pda::create([
                    'curriculum_version_id' => $version->id,
                    'curricular_content_id' => $content->id,
                    'grade_id' => $grade->id,
                    'code' => $code . '-PDA-' . $grade->code,
                    'full_text' => "PDA de ejemplo {$code}/{$grade->code} ({$textSuffix}). " . self::DEMO_NOTICE,
                    'sort_order' => $i + 1,
                ]);
            }
        }

        ArticulatingAxis::create([
            'curriculum_version_id' => $version->id,
            'code' => 'AX-CONV',
            'name' => 'Eje demo Convivencia',
            'description' => self::DEMO_NOTICE,
            'sort_order' => 1,
        ]);
        ArticulatingAxis::create([
            'curriculum_version_id' => $version->id,
            'code' => 'AX-INCL',
            'name' => 'Eje demo Inclusión',
            'description' => self::DEMO_NOTICE,
            'sort_order' => 2,
        ]);

        return $version->refresh();
    }

    protected function makeGrade(CurriculumVersion $version, EducationalPhase $phase, string $code, string $name, int $ordinal): Grade
    {
        return Grade::create([
            'curriculum_version_id' => $version->id,
            'educational_phase_id' => $phase->id,
            'code' => $code,
            'name' => $name,
            'ordinal' => $ordinal,
            'sort_order' => $ordinal,
        ]);
    }
}
