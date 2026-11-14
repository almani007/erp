<?php

declare(strict_types=1);

namespace Catalog\Domain\Exception;

use DomainException;

final class DuplicateBarcode extends DomainException
{
    public static function forBarcode(string $barcode): self
    {
        return new self("Barcode '{$barcode}' is already assigned to another product in this tenant.");
    }
}
