<?php

declare(strict_types=1);

namespace Integration\Presentation\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

/**
 * Makes a state-changing endpoint safe to retry.
 *
 * The client sends an `Idempotency-Key` header. The first request with a
 * key is executed and its response stored; a repeat with the same key and
 * the same body gets the stored response back and nothing is executed twice.
 * Apply after `auth:sanctum`.
 */
final class Idempotent
{
    private const int TTL_HOURS = 24;

    /** A "processing" record older than this is treated as an abandoned attempt. */
    private const int STALE_SECONDS = 120;

    public function handle(Request $request, Closure $next): Response
    {
        $key = $request->header('Idempotency-Key');
        $user = $request->user();

        if (! is_string($key) || $key === '' || strlen($key) > 120) {
            return $this->error(400, 'IDEMPOTENCY_KEY_REQUIRED', 'This endpoint requires an Idempotency-Key header (max 120 characters).');
        }

        $scope = ['tenant_id' => $user->tenant_id, 'actor_id' => $user->getKey(), 'idempotency_key' => $key];
        $hash = hash('sha256', $request->method().'|'.$request->path().'|'.$request->getContent());

        if (! $this->reserve($scope, $hash)) {
            $existing = DB::table('idempotency_records')->where($scope)->first();

            if ($existing === null) {
                return $this->error(409, 'IDEMPOTENCY_CONFLICT', 'Please retry the request.');
            }
            if ($existing->request_hash !== $hash) {
                return $this->error(422, 'IDEMPOTENCY_KEY_REUSED', 'This Idempotency-Key was already used with a different request.');
            }
            if ($existing->status === 'completed') {
                return (new JsonResponse(json_decode((string) $existing->response_body, true), (int) $existing->response_code))
                    ->header('Idempotent-Replayed', 'true');
            }

            return $this->error(409, 'REQUEST_IN_PROGRESS', 'The original request is still being processed.');
        }

        try {
            $response = $next($request);
        } catch (\Throwable $e) {
            DB::table('idempotency_records')->where($scope)->delete();

            throw $e;
        }

        if ($response->getStatusCode() >= 400) {
            // Nothing was committed (rejections roll back), so the key stays
            // free for a corrected or later retry.
            DB::table('idempotency_records')->where($scope)->delete();

            return $response;
        }

        DB::table('idempotency_records')->where($scope)->update([
            'status' => 'completed',
            'response_code' => $response->getStatusCode(),
            'response_body' => $response->getContent() ?: 'null',
        ]);

        return $response;
    }

    /**
     * Claim the key. Returns false when another request already holds it.
     *
     * @param  array{tenant_id: string, actor_id: string, idempotency_key: string}  $scope
     */
    private function reserve(array $scope, string $hash): bool
    {
        // Clear an expired record or an attempt that died mid-flight.
        DB::table('idempotency_records')->where($scope)->where(function ($q): void {
            $q->where('expires_at', '<', now())
                ->orWhere(fn ($s) => $s->where('status', 'processing')->where('created_at', '<', now()->subSeconds(self::STALE_SECONDS)));
        })->delete();

        return DB::table('idempotency_records')->insertOrIgnore($scope + [
            'id' => Uuid::v7()->toRfc4122(),
            'request_hash' => $hash,
            'status' => 'processing',
            'created_at' => now(),
            'expires_at' => now()->addHours(self::TTL_HOURS),
        ]) === 1;
    }

    private function error(int $status, string $code, string $message): JsonResponse
    {
        return new JsonResponse(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
