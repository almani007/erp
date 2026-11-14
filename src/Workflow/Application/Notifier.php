<?php

declare(strict_types=1);

namespace Workflow\Application;

use Workflow\Infrastructure\Persistence\Eloquent\InboxNotification;

/** In-app notifications, to a person or to everyone holding a role. Shown under the bell. */
final class Notifier
{
    public function toUser(string $userId, string $kind, string $title, ?string $body = null, ?string $link = null): void
    {
        InboxNotification::query()->create(['user_id' => $userId, 'kind' => $kind, 'title' => $title, 'body' => $body, 'link' => $link]);
    }

    public function toRole(string $role, string $kind, string $title, ?string $body = null, ?string $link = null): void
    {
        InboxNotification::query()->create(['role' => $role, 'kind' => $kind, 'title' => $title, 'body' => $body, 'link' => $link, 'read_by' => []]);
    }
}
