<?php

declare(strict_types=1);

namespace People\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use People\Application\ManagePayroll;
use People\Infrastructure\Persistence\Eloquent\PayrollRun;
use Shared\Infrastructure\Validation\TenantRule;

final class PayrollController
{
    public function index(): JsonResponse
    {
        return new JsonResponse(['data' => PayrollRun::query()->withCount('lines')->orderByDesc('period')->orderByDesc('created_at')->limit(240)->get()]);
    }

    public function show(string $runId): JsonResponse
    {
        return new JsonResponse(['data' => PayrollRun::query()
            ->with(['lines' => fn ($q) => $q->with('employee:id,code,name,designation,department,bank_name,bank_account')])
            ->findOrFail($runId)]);
    }

    public function store(Request $request, ManagePayroll $payroll, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            // Optional: most businesses have one company.
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'period' => ['required', 'date_format:Y-m'],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
        ]);
        if (isset($data['branch_id']) && ! $branches->canAccess($data['branch_id'])) {
            abort(403);
        }

        $companyId = $data['company_id'] ?? Company::query()->orderBy('created_at')->value('id');

        return new JsonResponse(['data' => $payroll->create($companyId, $data['period'], $data['branch_id'] ?? null)], 201);
    }

    public function adjust(Request $request, string $runId, string $lineId, ManagePayroll $payroll): JsonResponse
    {
        $data = $request->validate([
            'absent_days' => ['nullable', 'numeric', 'min:0', 'max:31'],
            'overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:400'],
            'bonus' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'other_deduction' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'tax' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'advance_recovery' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);
        $note = $data['note'] ?? null;
        unset($data['note']);

        return new JsonResponse(['data' => $payroll->adjustLine($runId, $lineId, array_map(static fn ($v) => $v === null ? null : (string) $v, $data), $note)]);
    }

    public function recalculate(string $runId, ManagePayroll $payroll): JsonResponse
    {
        return new JsonResponse(['data' => $payroll->recalculate($runId)]);
    }

    public function approve(string $runId, ManagePayroll $payroll): JsonResponse
    {
        return new JsonResponse(['data' => $payroll->approve($runId)]);
    }

    public function pay(Request $request, string $runId, ManagePayroll $payroll): JsonResponse
    {
        $data = $request->validate(['method' => ['required', 'in:cash,bank'], 'date' => ['required', 'date_format:Y-m-d']]);

        return new JsonResponse(['data' => $payroll->pay($runId, $data['method'], $data['date'])]);
    }

    public function cancel(string $runId, ManagePayroll $payroll): JsonResponse
    {
        return new JsonResponse(['data' => $payroll->cancel($runId)]);
    }
}
