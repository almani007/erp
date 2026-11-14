<?php

declare(strict_types=1);

namespace Integration\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class WebhookSubscription extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'webhook_subscriptions';

    protected $fillable = ['name', 'url', 'events', 'secret', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['events' => 'array'];
    }
}
