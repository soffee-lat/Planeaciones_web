<?php

namespace Database\Seeders;

use App\Actions\Commerce\CreateSubscription;
use App\Actions\Commerce\OpenSubscriptionPeriod;
use App\Actions\Planning\SyncPlanningRequestSelections;
use App\Actions\Plans\PublishPlanVersion;
use App\Enums\RoleCode;
use App\Models\Curriculum;
use App\Models\Grade;
use App\Models\Group;
use App\Models\GroupProfile;
use App\Models\Pda;
use App\Models\Plan;
use App\Models\PlanningRequest;
use App\Models\PlanVersion;
use App\Models\School;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class Phase3dDemoSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'testing')) {
            throw new \LogicException('Only local/testing demo data.');
        }
        $password = getenv('PHASE3D_DEMO_PASSWORD');
        if (! $password || strlen($password) < 12) {
            throw new \LogicException('Set PHASE3D_DEMO_PASSWORD (12+ characters) for these fictional accounts.');
        }
        if (! Curriculum::where('code', 'DEMO')->exists()) {
            $this->call(CurriculumDemoSeeder::class);
        }
        DB::transaction(function () use ($password): void {
            $admin = User::where('email', 'qa3d-admin@example.test')->first()
                ?? User::factory()->withRole(RoleCode::Administrator)->create(['email' => 'qa3d-admin@example.test', 'name' => 'Administración ficticia 3D', 'password' => $password]);
            $curriculum = Curriculum::where('code', 'DEMO')->firstOrFail();
            $grade = Grade::where('curriculum_version_id', $curriculum->selectable_version_id)->whereHas('pdas')->firstOrFail();
            $pda = Pda::where('grade_id', $grade->id)->firstOrFail();
            foreach (['basic', 'reviewed', 'short', 'none'] as $scenario) {
                $version = null;
                if ($scenario !== 'none') {
                    $plan = Plan::firstOrCreate(['code' => 'QA3D-'.strtoupper($scenario)], ['name' => 'Plan ficticio '.strtoupper($scenario), 'active' => true]);
                    $version = $plan->versions()->first();
                    if (! $version) {
                        $version = PlanVersion::factory()->create([
                            'plan_id' => $plan->id, 'max_planning_days' => 7,
                            'planning_limit' => $scenario === 'short' ? 2 : 8,
                            'human_review_required' => $scenario === 'reviewed',
                            'human_review_limit' => $scenario === 'reviewed' ? 8 : 0,
                            'correction_limit' => 2, 'group_limit' => 1,
                        ]);
                        app(PublishPlanVersion::class)($version, $admin);
                    }
                }
                foreach (['mobile', 'desktop'] as $size) {
                    $email = "qa3d-{$scenario}-{$size}@example.test";
                    if (User::where('email', $email)->exists()) {
                        continue;
                    }
                    $user = User::factory()->withRole(RoleCode::Customer)->create(['name' => "Docente ficticio {$scenario} {$size}", 'email' => $email, 'password' => $password, 'onboarding_completed_at' => now()]);
                    $school = School::factory()->create(['owner_id' => $user->id]);
                    $group = Group::create(['owner_id' => $user->id, 'school_id' => $school->id, 'curriculum_version_id' => $grade->curriculum_version_id, 'grade_id' => $grade->id, 'name' => 'Grupo de demostración 3D', 'school_year' => '2026-2027']);
                    GroupProfile::factory()->create(['group_id' => $group->id]);
                    $request = PlanningRequest::factory()->create(['owner_id' => $user->id, 'group_id' => $group->id, 'curriculum_version_id' => $grade->curriculum_version_id, 'grade_id' => $grade->id, 'project' => "Proyecto ficticio {$scenario} {$size}", 'starts_on' => '2026-10-01', 'ends_on' => '2026-10-28', 'creation_mode' => 'advanced']);
                    app(SyncPlanningRequestSelections::class)->execute($user, $request, ['contents' => [$pda->curricular_content_id], 'pdas' => [$pda->id]]);
                    if ($version) {
                        $sub = app(CreateSubscription::class)($user, $version->fresh(), now()->subHour());
                        app(OpenSubscriptionPeriod::class)($sub, now()->subHour(), now()->addDays(30));
                    }
                    $this->command?->info("{$email}: planning request {$request->id}");
                }
            }
        });
    }
}
