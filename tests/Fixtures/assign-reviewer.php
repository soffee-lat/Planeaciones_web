<?php

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

if (! app()->environment('testing') || config('database.default') !== 'pgsql' || ! str_ends_with(config('database.connections.pgsql.database'), '_test')) {
    throw new LogicException('Dedicated PostgreSQL test database required.');
}

echo Illuminate\Support\Facades\DB::selectOne('SELECT pg_backend_pid() AS pid')->pid."\n";
flush();

$request = App\Models\PlanningRequest::findOrFail($argv[1]);
$assignment = app(App\Actions\Review\AssignReviewer::class)->execute($request);
echo $assignment ? "ASSIGNED:{$assignment->id}\n" : "NO_REVIEWER\n";
