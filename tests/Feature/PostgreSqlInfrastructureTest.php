<?php
namespace Tests\Feature;
use App\Enums\RoleCode;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Fixtures\QueueProbe;
use Tests\TestCase;
class PostgreSqlInfrastructureTest extends TestCase {
    use DatabaseMigrations;
    public function test_only_pgsql_connection_is_configured_and_connected(): void {
        $this->assertSame(['pgsql'],array_keys(config('database.connections')));
        $this->assertSame('pgsql',DB::connection()->getDriverName());
        $this->assertStringContainsString('PostgreSQL',DB::selectOne('SELECT version() AS version')->version);
    }
    public function test_database_queue_is_processed_by_real_worker_and_dispatch_waits_for_commit(): void {
        config(['queue.default'=>'database']);
        $key='queue-probe-'.bin2hex(random_bytes(8));
        DB::beginTransaction();
        QueueProbe::dispatch($key)->onConnection('database')->onQueue('smoke');
        $this->assertSame(0,DB::table('jobs')->count());
        DB::commit();
        $this->assertSame(1,DB::table('jobs')->count());
        Artisan::call('queue:work',['connection'=>'database','--queue'=>'smoke','--once'=>true,'--tries'=>1,'--sleep'=>0]);
        $this->assertSame('processed',Cache::store('database')->get($key));
        $this->assertSame(0,DB::table('jobs')->count());
        $this->assertSame(0,DB::table('failed_jobs')->count());
        Cache::store('database')->forget($key);
    }
    public function test_rolled_back_transaction_does_not_enqueue_job(): void {
        config(['queue.default'=>'database']);
        DB::beginTransaction();
        QueueProbe::dispatch('rolled-back')->onConnection('database');
        DB::rollBack();
        $this->assertSame(0,DB::table('jobs')->count());
    }
    public function test_postgresql_row_lock_blocks_second_connection(): void {
        $user=User::factory()->withRole(RoleCode::Customer)->create();
        $config=config('database.connections.pgsql');
        $second=new \PDO('pgsql:host='.$config['host'].';port='.$config['port'].';dbname='.$config['database'],$config['username'],$config['password'],[\PDO::ATTR_ERRMODE=>\PDO::ERRMODE_EXCEPTION]);
        DB::beginTransaction();
        DB::table('users')->where('id',$user->id)->lockForUpdate()->first();
        try {
            $second->exec("SET lock_timeout = '150ms'");
            $statement=$second->prepare('UPDATE users SET name = ? WHERE id = ?');
            $statement->execute(['Blocked',$user->id]);
            $this->fail('A second connection must not bypass a row lock.');
        } catch (\PDOException $error) {
            $this->assertSame('55P03',$error->getCode());
        } finally { DB::rollBack(); }
        $this->assertNotSame('Blocked',$user->fresh()->name);
    }
    public function test_private_storage_has_no_public_route_or_symlink(): void {
        $path='tests/'.bin2hex(random_bytes(8)).'.txt';
        Storage::disk('private')->put($path,'private fixture');
        try {
            $this->assertSame('private fixture',Storage::disk('private')->get($path));
            $this->assertFalse(config('filesystems.disks.private.serve'));
            $this->assertSame([],config('filesystems.links'));
            $this->assertFalse(is_link(public_path('storage')));
            $this->get('/storage/'.$path)->assertNotFound();
        } finally { Storage::disk('private')->delete($path); }
    }
}
