<?php

declare(strict_types=1);

namespace Integration\Application;

use Illuminate\Support\Facades\DB;
use Integration\Infrastructure\Persistence\Eloquent\FiscalSubmission;
use Integration\Infrastructure\Persistence\Eloquent\WebhookDelivery;
use Shared\Domain\Contract\TenantContext;

/**
 * Runs every minute (`integration:relay`) across all tenants:
 *   1. publishes new outbox events — FBR submissions are queued for sales
 *      and returns, and webhook deliveries are created for subscribers;
 *   2. sends FBR submissions that are due (first try or retry);
 *   3. delivers webhooks that are due.
 * Each event is handled inside its own tenant.
 */
final class IntegrationRelay
{
    public function __construct(
        private readonly TenantContext $tenant,
        private readonly FbrReporter $fbr,
        private readonly WebhookDispatcher $webhooks,
        private readonly AutoMessages $messages,
    ) {}

    /** @return array{events: int, fiscal: int, webhooks: int} */
    public function run(int $batch = 200): array
    {
        $events = DB::table('outbox_events')->whereNull('published_at')->orderBy('occurred_at')->limit($batch)->get();
        foreach ($events as $event) {
            $this->tenant->set($event->tenant_id);
            try {
                match ($event->event_type) {
                    'sales.sale.completed' => [$this->fbr->queue('sale', $event->aggregate_id), $this->messages->afterSale($event->aggregate_id)],
                    'sales.sale.returned' => $this->fbr->queue('sales_return', $event->aggregate_id),
                    default => null,
                };
                $this->webhooks->fanOut($event);
                DB::table('outbox_events')->where('id', $event->id)->update(['published_at' => now(), 'attempts' => $event->attempts + 1]);
            } catch (\Throwable $e) {
                // Left unpublished; retried on the next run.
                DB::table('outbox_events')->where('id', $event->id)->update(['attempts' => $event->attempts + 1]);
                report($e);
            }
        }

        $fiscal = DB::table('fiscal_submissions')->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit($batch)->get(['id', 'tenant_id']);
        foreach ($fiscal as $row) {
            $this->tenant->set($row->tenant_id);
            $sub = FiscalSubmission::query()->find($row->id);
            if ($sub !== null) {
                $this->fbr->submit($sub);
            }
        }

        $deliveries = DB::table('webhook_deliveries')->where('status', 'pending')->where('next_attempt_at', '<=', now())
            ->orderBy('next_attempt_at')->limit($batch)->get(['id', 'tenant_id', 'outbox_event_id']);
        foreach ($deliveries as $row) {
            $this->tenant->set($row->tenant_id);
            $event = DB::table('outbox_events')->where('id', $row->outbox_event_id)->first();
            $delivery = WebhookDelivery::query()->find($row->id);
            if ($event !== null && $delivery !== null) {
                $this->webhooks->deliver($delivery, array_merge((array) $event, ['payload' => json_decode((string) $event->payload, true)]));
            }
        }

        return ['events' => $events->count(), 'fiscal' => $fiscal->count(), 'webhooks' => $deliveries->count()];
    }
}
