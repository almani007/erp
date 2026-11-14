<?php

declare(strict_types=1);

namespace People\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use People\Application\GiveAdvance;
use People\Infrastructure\Persistence\Eloquent\Employee;
use People\Infrastructure\Persistence\Eloquent\EmployeeAdvance;
use Shared\Infrastructure\Validation\TenantRule;

final class AdvanceController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => EmployeeAdvance::query()->with('employee:id,code,name')->orderByDesc('date')->limit(2000)->get()]);
    }

    public function store(Request $request, GiveAdvance $action): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['required', 'uuid', TenantRule::exists('employees')],
            'date' => ['required', 'date_format:Y-m-d'],
            'kind' => ['required', 'in:advance,loan'],
            'amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'installment' => ['nullable', 'numeric', 'gt:0', 'decimal:0,2'],
            'paid_from' => ['required', 'in:cash,bank'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $data['amount'] = (string) $data['amount'];
        $data['installment'] = isset($data['installment']) ? (string) $data['installment'] : null;

        $employee = Employee::query()->findOrFail($data['employee_id']);
        $pending = app(\Workflow\Application\Approvals::class)->gate('employee_advance', $data['amount'],
            ucfirst($data['kind'])." of {$data['amount']} to {$employee->name}", ['employee_id' => $employee->id, 'data' => $data], $employee->branch_id);
        if ($pending !== null) {
            return new JsonResponse(['data' => ['status' => 'pending_approval', 'approval_request' => $pending]], 202);
        }

        return new JsonResponse(['data' => $action->execute($employee, $data)], 201);
    }
}
