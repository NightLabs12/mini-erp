<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schedule;

// Mode demo mengembalikan data ke kondisi awal setiap dini hari.
Schedule::command('demo:reset --force')
    ->dailyAt('02:00')
    ->when(static fn (): bool => (bool) config('app.demo_mode'));
