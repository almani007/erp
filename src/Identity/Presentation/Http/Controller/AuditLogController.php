<?php

declare(strict_types=1);

namespace Identity\Presentation\Http\Controller;

use Identity\Infrastructure\Persistence\Eloquent\AuditLog;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AuditLogController
{
    public function index(Request $request): JsonResponse
    {
        $q = $request->validate([
            'subject_type' => ['nullable', 'string', 'max:120'],
            'subject_id' => ['nullable', 'uuid'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $logs = AuditLog::query()
            ->with('actor:id,name,email')
            ->when($q['subject_type'] ?? null, fn ($b, $v) => $b->where('subject_type', $v))
            ->when($q['subject_id'] ?? null, fn ($b, $v) => $b->where('subject_id', $v))
            ->when($q['from'] ?? null, fn ($b, $v) => $b->where('occurred_at', '>=', $v))
            ->when($q['to'] ?? null, fn ($b, $v) => $b->where('occurred_at', '<=', $v))
            ->orderByDesc('occurred_at')
            ->limit(500)
            ->get();

        return new JsonResponse(['data' => $logs]);
    }
}
