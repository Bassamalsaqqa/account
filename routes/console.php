<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

Schedule::command('ai-assistant:prune')->hourly()->withoutOverlapping();

// Bounded read-only six-domain reconciliation orchestration (Default OFF until approved configuration)
Schedule::command('reconciliation:run')
    ->dailyAt('02:00')
    ->withoutOverlapping((int) ceil(config('reconciliation.lock_ttl_seconds', 300) / 60))
    ->when(fn () => (bool) config('reconciliation.enabled', false));
