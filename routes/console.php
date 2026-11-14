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

// Rent invoices for active rental contracts, for every tenant.
Artisan::command('rentals:bill', function (Rentals\Application\ManageRentals $rentals): void {
    $result = $rentals->billDue(null);
    $this->info("Raised {$result['invoiced']} rent invoices; {$result['failed']} failed (see the contract).");
})->purpose('Invoice rent that has fallen due');
Schedule::command('rentals:bill')->dailyAt('01:20')->withoutOverlapping();

// FBR reporting, webhooks and receipts-by-message, from the transactional outbox.
Artisan::command('integration:relay', function (Integration\Application\IntegrationRelay $relay): void {
    $r = $relay->run();
    $this->info("Events {$r['events']}, FBR {$r['fiscal']}, webhooks {$r['webhooks']}.");
})->purpose('Publish outbox events: FBR, webhooks, messages');
Schedule::command('integration:relay')->everyMinute()->withoutOverlapping();

// Overdue invoice and appointment reminders.
Artisan::command('integration:reminders', function (Integration\Application\AutoMessages $messages): void {
    $this->info('Sent '.$messages->daily().' reminders.');
})->purpose('Send overdue-invoice and appointment reminders');
Schedule::command('integration:reminders')->dailyAt('18:05')->withoutOverlapping();

// Morning alerts under the bell: low stock, expiring batches, FBR failures, overdue invoices.
Artisan::command('workflow:alerts', function (Workflow\Application\DailyAlerts $alerts): void {
    $this->info('Raised '.$alerts->run().' alerts.');
})->purpose('Raise daily alerts');
Schedule::command('workflow:alerts')->dailyAt('07:40')->withoutOverlapping();
Schedule::call(static fn () => DB::table('idempotency_records')->where('expires_at', '<', now())->delete())
    ->daily()
    ->name('prune-idempotency-records');

// Gift cards past their expiry date: the unspent value becomes income.
Artisan::command('sales:expire-gift-cards', function (Sales\Application\GiftCards $cards): void {
    $this->info('Expired '.$cards->expireDue().' gift cards.');
})->purpose('Expire gift cards past their date');
Schedule::command('sales:expire-gift-cards')->dailyAt('00:40')->withoutOverlapping();
