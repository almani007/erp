<?php

declare(strict_types=1);

namespace People\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use People\Infrastructure\Persistence\Eloquent\Employee;
use Shared\Domain\Contract\AuditTrail;
use Shared\Infrastructure\Validation\TenantRule;

final class EmployeeController
{
    public function index(BranchContext $branches): JsonResponse
    {
        $allowed = $branches->allowedBranchIds();

        return new JsonResponse(['data' => Employee::query()
            ->when($allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $allowed)))
            ->orderBy('code')->limit(5000)->get()]);
    }

    public function store(Request $request, AuditTrail $audit): JsonResponse
    {
        $data = $request->validate($this->rules() + [
            // Optional: most businesses have one company.
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'code' => ['required', 'string', 'max:30', 'alpha_dash', TenantRule::unique('employees', 'code')],
        ]);
        $data['company_id'] ??= Company::query()->orderBy('created_at')->value('id');
        $employee = Employee::query()->create($data + ['status' => 'active']);
        $audit->record('people.employee.created', 'employee', $employee->id, null, ['code' => $employee->code]);

        return new JsonResponse(['data' => $employee], 201);
    }

    public function update(Request $request, string $employeeId, AuditTrail $audit): JsonResponse
    {
        $employee = Employee::query()->findOrFail($employeeId);
        $data = $request->validate(array_map(
            static fn (array $rules): array => array_map(static fn ($r) => $r === 'required' ? 'sometimes' : $r, $rules),
            $this->rules(),
        ) + ['status' => ['sometimes', 'in:active,inactive']]);

        $before = $employee->only(['basic_salary', 'allowances', 'deductions', 'monthly_tax', 'status']);
        $employee->update($data);
        // Salary changes are sensitive: keep the before and after.
        $audit->record('people.employee.updated', 'employee', $employee->id, $before, $employee->only(array_keys($before)));

        return new JsonResponse(['data' => $employee]);
    }

    /** @return array<string, list<mixed>> */
    private function rules(): array
    {
        return [
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
            'name' => ['required', 'string', 'max:150'],
            'father_name' => ['nullable', 'string', 'max:150'],
            'cnic' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:150'],
            'address' => ['nullable', 'string', 'max:300'],
            'department' => ['nullable', 'string', 'max:80'],
            'designation' => ['nullable', 'string', 'max:80'],
            'join_date' => ['required', 'date_format:Y-m-d'],
            'leave_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:join_date'],
            'pay_type' => ['required', 'in:monthly,daily'],
            'basic_salary' => ['required', 'numeric', 'min:0', 'decimal:0,2'],
            'allowances' => ['nullable', 'array', 'max:20'],
            'allowances.*.name' => ['required', 'string', 'max:60'],
            'allowances.*.amount' => ['required', 'numeric', 'min:0'],
            'deductions' => ['nullable', 'array', 'max:20'],
            'deductions.*.name' => ['required', 'string', 'max:60'],
            'deductions.*.amount' => ['required', 'numeric', 'min:0'],
            'monthly_tax' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'annual_leave_days' => ['nullable', 'numeric', 'min:0', 'max:365'],
            'bank_name' => ['nullable', 'string', 'max:100'],
            'bank_account' => ['nullable', 'string', 'max:60'],
            'custom' => ['nullable', 'array'],
        ];
    }
}
