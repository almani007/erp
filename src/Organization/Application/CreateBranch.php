<?php

declare(strict_types=1);

namespace Organization\Application;

use Organization\Infrastructure\Persistence\Eloquent\Branch;

final readonly class CreateBranch
{
    /**
     * @param  array{company_id: string, code: string, name: string, timezone: string, address?: array, phone?: string, email?: string}  $data
     */
    public function execute(array $data): Branch
    {
        return Branch::create([
            'company_id' => $data['company_id'],
            'code'       => $data['code'],
            'name'       => $data['name'],
            'timezone'   => $data['timezone'],
            'address'    => $data['address'] ?? null,
            'phone'      => $data['phone'] ?? null,
            'email'      => $data['email'] ?? null,
            'status'     => 'active',
        ]);
    }
}
