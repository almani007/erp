<?php

declare(strict_types=1);

namespace Organization\Application;

use Organization\Infrastructure\Persistence\Eloquent\Warehouse;

final readonly class CreateWarehouse
{
    /**
     * @param  array{company_id: string, branch_id: string, code: string, name: string}  $data
     */
    public function execute(array $data): Warehouse
    {
        return Warehouse::create([
            'company_id' => $data['company_id'],
            'branch_id'  => $data['branch_id'],
            'code'       => $data['code'],
            'name'       => $data['name'],
            'status'     => 'active',
        ]);
    }
}
