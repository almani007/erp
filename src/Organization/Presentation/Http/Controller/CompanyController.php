<?php

declare(strict_types=1);

namespace Organization\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Application\CreateCompany;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Infrastructure\Validation\TenantRule;

final class CompanyController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => Company::query()->orderBy('legal_name')->get()]);
    }

    public function show(string $companyId): JsonResponse
    {
        return new JsonResponse(['data' => Company::with('branches')->findOrFail($companyId)]);
    }

    public function store(Request $request, CreateCompany $action): JsonResponse
    {
        $validated = $request->validate([
            'code' => ['required', 'string', 'max:30', 'alpha_dash', TenantRule::unique('companies', 'code')],
            'legal_name' => ['required', 'string', 'max:200'],
            'base_currency' => ['required', 'string', 'size:3'],
            'tax_registration_no' => ['nullable', 'string', 'max:80'],
            'fiscal_year_start_month' => ['nullable', 'integer', 'min:1', 'max:12'],
        ]);

        return new JsonResponse(['data' => $action->execute($validated)], 201);
    }

    public function update(Request $request, string $companyId): JsonResponse
    {
        $company = Company::findOrFail($companyId);

        $company->update($request->validate([
            'legal_name' => ['sometimes', 'string', 'max:200'],
            'tax_registration_no' => ['nullable', 'string', 'max:80'],
        ]));

        return new JsonResponse(['data' => $company->fresh()]);
    }
}
