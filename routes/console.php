<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schedule;

Schedule::command('sanctum:prune-expired --hours=24')->daily();

// Recurring journals (rent, accruals, instalments) fall due by date, for every tenant.
Artisan::command('finance:recurring', function (Finance\Application\RunRecurringJournals $runner): void {
    $result = $runner->runDue(null);
    $this->info("Posted {$result['posted']} recurring journals; {$result['failed']} failed (see the template for the reason).");
})->purpose('Post recurring journals that are due');
Schedule::command('finance:recurring')->dailyAt('01:10')->withoutOverlapping();
Schedule::call(static fn () => DB::table('idempotency_records')->where('expires_at', '<', now())->delete())
    ->daily()
    ->name('prune-idempotency-records');
