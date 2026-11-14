<?php

declare(strict_types=1);

namespace Integration\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class MessageLog extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'message_log';

    protected $fillable = ['channel', 'recipient', 'subject', 'body', 'status', 'error', 'related_type', 'related_id', 'provider_id', 'created_by'];
}
