<?php

namespace Tests\Feature\Commerce;

use App\Enums\PlanningRequestStatus;
use App\Models\PlanningRequest;
use Illuminate\Foundation\Testing\RefreshDatabaseState;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Process\Process;
use Tests\Concerns\CreatesCommercialPlanningScenario;
use Tests\Feature\PedagogyTestCase;

class PlanningCommercialConcurrencyTest extends PedagogyTestCase
{
    use CreatesCommercialPlanningScenario;

    /** Committed fixtures are necessary for independent PostgreSQL sessions. */
    public function refreshDatabase(): void
    {
        Artisan::call('migrate:fresh', ['--force' => true]);
        RefreshDatabaseState::$migrated = false;
        $this->beforeApplicationDestroyed(function (): void {
            Artisan::call('migrate:rollback', ['--force' => true]);
            RefreshDatabaseState::$migrated = false;
        });
    }

    public function test_two_real_transactions_compete_for_last_units_and_only_one_authorizes(): void
    {
        $first = $this->draft(14);
        $period = $this->period($first, ['planning_limit' => 2]);
        $second = $first->replicate();
        $second->save();
        $pda = $first->pdas()->firstOrFail();
        app(\App\Actions\Planning\SyncPlanningRequestSelections::class)->execute($second->owner, $second, ['contents' => [$pda->curricular_content_id], 'pdas' => [$pda->id]]);
        $this->confirm($first);
        $this->confirm($second);
        $config = config('database.connections.pgsql');
        $processes = [];
        DB::beginTransaction();
        DB::table('subscription_periods')->where('id', $period->id)->lockForUpdate()->first();
        try {
            foreach ([$first, $second] as $request) {
                $process = new Process([PHP_BINARY, '-c', php_ini_loaded_file(), base_path('tests/Fixtures/authorize-planning.php'), (string) $request->id], base_path(), [
                    'APP_ENV' => 'testing', 'DB_CONNECTION' => 'pgsql', 'DB_URL' => '',
                    'DB_HOST' => $config['host'], 'DB_PORT' => (string) $config['port'],
                    'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'],
                ]);
                $process->setTimeout(25)->start();
                $processes[] = $process;
            }
            $deadline = microtime(true) + 15;
            $blocked = 0;
            do {
                DB::select('SELECT pg_stat_clear_snapshot()');
                $pids = array_map(fn (Process $p): int => (int) trim(explode("\n", $p->getOutput())[0]), $processes);
                $blocked = DB::table('pg_stat_activity')->whereIn('pid', $pids)->where('wait_event_type', 'Lock')->count();
                if ($blocked === 2) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $this->assertSame(2, $blocked, 'Both independent PostgreSQL workers must actually wait for a lock. '.json_encode(array_map(fn (Process $p): array => [$p->getOutput(), $p->getErrorOutput()], $processes)));
            DB::commit();
            $results = [];
            foreach ($processes as $process) {
                $this->assertSame(0, $process->wait(), $process->getErrorOutput());
                $lines = explode("\n", trim($process->getOutput()));
                $results[] = end($lines);
            }
            sort($results);
            $this->assertSame(['AUTHORIZED', 'INSUFFICIENT_PLANNING_UNITS'], $results);
            $this->assertSame(1, PlanningRequest::where('status', PlanningRequestStatus::LISTA_PARA_PROCESAR)->count());
            $this->assertSame(1, PlanningRequest::where('status', PlanningRequestStatus::ESPERANDO_PAGO)->count());
            $this->assertSame(1, DB::table('usage_reservations')->count());
            $this->assertSame(2, (int) DB::table('usage_reservations')->sum('quantity'));
            $this->assertSame(2, DB::table('planning_request_segments')->count());
            $this->assertSame(1, DB::table('request_state_events')->where('to_status', 'LISTA_PARA_PROCESAR')->count());
            $this->assertSame(0, DB::table('usage_reservations')->whereNotNull('consumed_at')->count());
        } finally {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
    }
}
