<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();
Schedule::call(static fn () => DB::table('idempotency_records')->where('expires_at', '<', now())->delete())
    ->daily()
    ->name('prune-idempotency-records');
