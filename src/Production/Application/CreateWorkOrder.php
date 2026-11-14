<?php

declare(strict_types=1);

namespace Production\Application;

use Illuminate\Support\Facades\DB;
use Production\Domain\Enum\WorkOrderStatus;
use Production\Domain\Exception\RecipeNotActive;
use Production\Infrastructure\Persistence\Eloquent\ProductionOrder;
use Production\Infrastructure\Persistence\Eloquent\RecipeVersion;
use Shared\Domain\Contract\DocumentNumbers;

final readonly class CreateWorkOrder
{
    public function __construct(
        private DocumentNumbers $documentNumbers,
    ) {}

    public function handle(array $data): ProductionOrder
    {
        $recipeVersion = RecipeVersion::where('recipe_id', $data['recipe_id'])
            ->where('status', 'active')
            ->where('effective_from', '<=', now())
            ->where(fn ($q) => $q->whereNull('effective_to')->orWhere('effective_to', '>=', now()))
            ->latest('version')
            ->first();

        if (! $recipeVersion) {
            throw RecipeNotActive::forRecipe($data['recipe_id']);
        }

        return DB::transaction(function () use ($data, $recipeVersion): ProductionOrder {
            $orderNo = $this->documentNumbers->next(
                $data['tenant_id'],
                $data['company_id'],
                'WO',
            );

            return ProductionOrder::create([
                'tenant_id' => $data['tenant_id'],
                'company_id' => $data['company_id'],
                'branch_id' => $data['branch_id'],
                'order_no' => $orderNo,
                'recipe_version_id' => $recipeVersion->id,
                'planned_qty' => $data['planned_qty'],
                'actual_output_qty' => '0',
                'scrap_qty' => '0',
                'wastage_qty' => '0',
                'status' => WorkOrderStatus::Planned,
                'planned_start' => $data['planned_start'] ?? null,
                'planned_end' => $data['planned_end'] ?? null,
                'work_center_id' => $data['work_center_id'] ?? null,
            ]);
        });
    }
}
