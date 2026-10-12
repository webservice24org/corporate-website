<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command(
    'crm:send-follow-up-notifications'
)
    ->everyMinute()
    ->withoutOverlapping();

// Inventory alerts: reconcile every five minutes, without overlapping runs.
// The command is discovered automatically from app/Console/Commands in standard Laravel 12 apps.
Schedule::command('inventory:scan-low-stock-alerts')
    ->everyFiveMinutes()
    ->withoutOverlapping(15);
