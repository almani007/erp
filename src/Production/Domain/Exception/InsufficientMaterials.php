<?php

declare(strict_types=1);

namespace Production\Domain\Exception;

final class InsufficientMaterials extends \DomainException
{
    /** @param array<string, array{required: string, available: string}> $shortages */
    public static function forWorkOrder(string $orderId, array $shortages): self
    {
        $details = implode(', ', array_map(
            fn (string $productId, array $qty) => "{$productId}: need {$qty['required']}, have {$qty['available']}",
            array_keys($shortages),
            $shortages,
        ));

        return new self("Insufficient materials for work order {$orderId}: {$details}");
    }
}
