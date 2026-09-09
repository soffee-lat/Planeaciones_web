<?php

namespace Tests\Feature\Review;

use App\Enums\ReviewAssignmentStatus;
use App\Enums\UsageReservationStatus;
use App\Enums\UsageResource;
use App\Models\ReviewerAssignment;
use App\Models\ReviewerAvailability;
use App\Models\ReviewerProfile;
use App\Models\RequestBlock;
use App\Models\UsageReservation;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\BuildsGeneratedPlanDraft;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Concerns\CreatesManualAiPipelineScenario;
use Tests\Concerns\CreatesReviewerScenario;
use Tests\Feature\PedagogyTestCase;

class ReviewerAssignmentConcurrencyTest extends PedagogyTestCase
{
    use BuildsGeneratedPlanDraft;
    use CreatesCommercialPlanningScenario;
    use CreatesManualAiPipelineScenario;
    use CreatesReviewerScenario;

    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_dos_solicitudes_compiten_por_ultima_capacidad_y_solo_una_se_asigna(): void
    {
        $first = $this->humanReviewReadyRequest()['request'];
        $second = $this->humanReviewReadyRequest()['request'];
        $reviewer = ReviewerProfile::factory()->create([
            'user_id' => $this->reviewer()->id,
            'max_load' => 4,
            'daily_max' => 8,
            'rate_minor' => 2000,
            'currency' => 'MXN',
        ]);
        foreach ([$first, $second] as $request) {
            DB::table('reviewer_grades')->insertOrIgnore([
                'reviewer_id' => $reviewer->user_id,
                'grade_id' => $request->grade_id,
                'curriculum_version_id' => $request->curriculum_version_id,
                'created_at' => now(),
            ]);
        }
        ReviewerAvailability::factory()->create([
            'reviewer_id' => $reviewer->user_id,
            'starts_at' => now()->subHour(),
            'ends_at' => now()->addDays(7),
        ]);

        $config = config('database.connections.pgsql');
        $processes = [];
        DB::beginTransaction();
        DB::table('reviewer_profiles')->where('id', $reviewer->id)->lockForUpdate()->first();
        try {
            foreach ([$first, $second] as $request) {
                $process = new Process([
                    PHP_BINARY,
                    '-c',
                    php_ini_loaded_file(),
                    base_path('tests/Fixtures/assign-reviewer.php'),
                    (string) $request->id,
                ], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
                    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                ]);
                $process->setTimeout(30)->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 15;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $pids = array_map(fn (Process $p): int => (int) trim(explode("\n", $p->getOutput())[0]), $processes);
                $blocked = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) break;
                usleep(20000);
            } while (microtime(true) < $deadline);

            $this->assertSame(2, $blocked, 'Both workers must wait on the reviewer capacity lock.');
            DB::commit();

            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = end($lines);
            }

            $this->assertCount(1, array_filter($results, fn (string $r): bool => str_starts_with($r, 'ASSIGNED:')));
            $this->assertCount(1, array_filter($results, fn (string $r): bool => $r === 'NO_REVIEWER'));
            $this->assertSame(1, ReviewerAssignment::query()->where('status', ReviewAssignmentStatus::Assigned->value)->count());
            $this->assertSame(1, RequestBlock::query()->where('code', 'no_reviewer')->whereNull('resolved_at')->count());
            $this->assertSame(1, UsageReservation::query()->where('resource', UsageResource::HumanReview->value)->where('status', UsageReservationStatus::Consumed->value)->count());
            $this->assertSame(1, UsageReservation::query()->where('resource', UsageResource::HumanReview->value)->where('status', UsageReservationStatus::Reserved->value)->count());
        } finally {
            if (DB::transactionLevel() > 0) DB::rollBack();
            foreach ($processes as $process) {
                if ($process->isRunning()) $process->stop();
            }
        }
    }
}
