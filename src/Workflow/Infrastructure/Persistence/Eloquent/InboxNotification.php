<?php

declare(strict_types=1);

namespace Workflow\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class InboxNotification extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'notifications_inbox';

    protected $fillable = ['user_id', 'role', 'kind', 'title', 'body', 'link', 'read_by', 'read_at'];

    protected function casts(): array
    {
        return ['read_by' => 'array', 'read_at' => 'datetime'];
    }
}
