<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Domain\Enum\QualityStatus;
use Production\Infrastructure\Persistence\Eloquent\QualityInspection;

final class InspectQuality
{
    /**
     * @param array{
     *     production_order_id: string,
     *     product_id: string,
     *     batch_id?: string|null,
     *     status: string,
     *     inspected_by: string,
     *     notes?: string|null,
     * } $data
     */
    public function execute(array $data): QualityInspection
    {
        return DB::transaction(function () use ($data): QualityInspection {
            $status = QualityStatus::from($data['status']);

            return QualityInspection::create([
                'production_order_id' => $data['production_order_id'],
                'product_id' => $data['product_id'],
                'batch_id' => $data['batch_id'] ?? null,
                'status' => $status,
                'inspected_by' => $data['inspected_by'],
                'notes' => $data['notes'] ?? null,
                'inspected_at' => now(),
            ]);
        });
    }
}
