<?php

declare(strict_types=1);

namespace Integration\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class WebhookDelivery extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'webhook_deliveries';

    protected $fillable = ['subscription_id', 'outbox_event_id', 'event_type', 'status', 'attempts', 'response_code', 'response_body', 'next_attempt_at', 'delivered_at'];

    protected function casts(): array
    {
        return ['next_attempt_at' => 'datetime', 'delivered_at' => 'datetime'];
    }
}
