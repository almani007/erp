<?php

declare(strict_types=1);

namespace Organization\Application;

use Organization\Infrastructure\Persistence\Eloquent\Company;

final readonly class CreateCompany
{
    /**
     * @param  array{code: string, legal_name: string, base_currency: string, tax_registration_no?: string, fiscal_year_start_month?: int}  $data
     */
    public function execute(array $data): Company
    {
        return Company::create([
            'code'                  => $data['code'],
            'legal_name'            => $data['legal_name'],
            'base_currency'         => $data['base_currency'],
            'tax_registration_no'   => $data['tax_registration_no'] ?? null,
            'fiscal_year_start_month' => $data['fiscal_year_start_month'] ?? 1,
        ]);
    }
}
