<?php

declare(strict_types=1);

namespace Shared\Infrastructure\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Shared\Domain\Exception\InsufficientPermission;
use Symfony\Component\Uid\Uuid;

/**
 * Maps domain rule violations to the API error envelope.
 */
final class DomainExceptionRenderer
{
    public function render(Request $request, \DomainException $e): JsonResponse
    {
        $status = $e instanceof InsufficientPermission ? 403 : 422;

        return new JsonResponse([
            'error' => [
                'code' => $this->errorCode($e),
                'message' => $e->getMessage(),
            ],
            'meta' => [
                'request_id' => $request->header('X-Request-ID') ?? Uuid::v7()->toRfc4122(),
            ],
        ], $status);
    }

    private function errorCode(\Throwable $e): string
    {
        $class = (new \ReflectionClass($e))->getShortName();

        // CurrencyMismatch → CURRENCY_MISMATCH
        return strtoupper((string) preg_replace('/(?<!^)[A-Z]/', '_$0', $class));
    }
}
