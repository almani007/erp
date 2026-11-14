<?php

declare(strict_types=1);

namespace Identity\Infrastructure\Audit;

use Identity\Infrastructure\Persistence\Eloquent\AuditLog;
use Illuminate\Http\Request;
use Shared\Domain\Contract\AuditTrail;

final class DatabaseAuditTrail implements AuditTrail
{
    public function __construct(private readonly Request $request) {}

    public function record(
        string $action,
        string $subjectType,
        ?string $subjectId,
        ?array $before = null,
        ?array $after = null,
        ?string $reason = null,
    ): void {
        AuditLog::query()->create([
            'actor_id' => $this->request->user('sanctum')?->getKey(),
            'action' => $action,
            'subject_type' => $subjectType,
            'subject_id' => $subjectId,
            'before' => $before,
            'after' => $after,
            'reason' => $reason,
            'ip' => $this->request->ip(),
            'user_agent' => mb_substr((string) $this->request->userAgent(), 0, 500),
            'occurred_at' => now(),
        ]);
    }
}
