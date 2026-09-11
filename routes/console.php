<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ai:process-outbox --limit=25')->everyMinute()->withoutOverlapping();
Schedule::command('documents:purge-expired')->dailyAt('03:20')->withoutOverlapping();
Schedule::command('notifications:queue-renewals')
    ->dailyAt('08:10')
    ->timezone((string) config('operational_notifications.business_timezone', 'America/Mexico_City'))
    ->withoutOverlapping();
Schedule::command('notifications:process-operational')->everyMinute()->withoutOverlapping();
