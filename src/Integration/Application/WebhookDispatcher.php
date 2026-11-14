<?php

declare(strict_types=1);

namespace Integration\Application;

use Illuminate\Support\Facades\Http;
use Integration\Infrastructure\Persistence\Eloquent\WebhookDelivery;
use Integration\Infrastructure\Persistence\Eloquent\WebhookSubscription;

/**
 * Posts business events to other systems (e-commerce site, accounting
 * add-on, BI). Each request carries:
 *   X-ERP-Event: sales.sale.completed
 *   X-ERP-Delivery: <delivery id>
 *   X-ERP-Signature: sha256=<HMAC-SHA256 of the raw body with the subscription secret>
 * Anything but a 2xx answer is retried with back-off, up to 10 times.
 */
final class WebhookDispatcher
{
    public const int MAX_ATTEMPTS = 10;

    /** @param  object{id: string, tenant_id: string, event_type: string}  $event */
    public function fanOut(object $event): void
    {
        foreach (WebhookSubscription::query()->where('status', 'active')->get() as $sub) {
            if (! in_array('*', $sub->events, true) && ! in_array($event->event_type, $sub->events, true)) {
                continue;
            }
            WebhookDelivery::query()->firstOrCreate(
                ['subscription_id' => $sub->id, 'outbox_event_id' => $event->id],
                ['event_type' => $event->event_type, 'status' => 'pending', 'next_attempt_at' => now()],
            );
        }
    }

    /** @param  array<string, mixed>  $event */
    public function deliver(WebhookDelivery $delivery, array $event): void
    {
        $sub = WebhookSubscription::query()->find($delivery->subscription_id);
        if ($sub === null || $sub->status !== 'active') {
            $delivery->update(['status' => 'failed', 'response_body' => 'Subscription removed or paused.']);

            return;
        }

        $body = json_encode([
            'id' => $event['id'], 'type' => $event['event_type'], 'occurred_at' => $event['occurred_at'],
            'aggregate' => ['type' => $event['aggregate_type'], 'id' => $event['aggregate_id']], 'data' => $event['payload'],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);

        $attempts = $delivery->attempts + 1;
        try {
            $response = Http::timeout(10)->withHeaders([
                'X-ERP-Event' => $event['event_type'],
                'X-ERP-Delivery' => $delivery->id,
                'X-ERP-Signature' => 'sha256='.hash_hmac('sha256', $body, $sub->secret),
            ])->withBody($body, 'application/json')->post($sub->url);

            if ($response->successful()) {
                $delivery->update(['status' => 'delivered', 'attempts' => $attempts, 'response_code' => $response->status(), 'delivered_at' => now(),
                    'response_body' => mb_substr($response->body(), 0, 1000)]);

                return;
            }
            $code = $response->status();
            $text = mb_substr($response->body(), 0, 1000);
        } catch (\Throwable $e) {
            $code = null;
            $text = mb_substr($e->getMessage(), 0, 1000);
        }

        $delivery->update([
            'attempts' => $attempts, 'response_code' => $code, 'response_body' => $text,
            'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
            'next_attempt_at' => now()->addMinutes(min(120, 2 ** min(7, $attempts - 1))),
        ]);
    }
}
