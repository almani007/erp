<?php

declare(strict_types=1);

namespace Production\Application;

use Production\Domain\Enum\QualityResult;
use Production\Infrastructure\Persistence\Eloquent\QualityInspection;

final class RecordQualityInspection
{
    public function handle(array $data): QualityInspection
    {
        return QualityInspection::create([
            'production_order_id' => $data['production_order_id'],
            'inspector_id' => $data['inspector_id'],
            'result' => QualityResult::from($data['result']),
            'notes' => $data['notes'] ?? null,
            'inspected_at' => now(),
        ]);
    }
}
