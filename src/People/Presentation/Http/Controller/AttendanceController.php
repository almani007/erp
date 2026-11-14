<?php

declare(strict_types=1);

namespace People\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use People\Infrastructure\Persistence\Eloquent\Attendance;
use People\Infrastructure\Persistence\Eloquent\Employee;
use Shared\Infrastructure\Validation\TenantRule;

/** The daily register: one sheet per day, every working employee on it. */
final class AttendanceController
{
    public const array STATUSES = ['present', 'absent', 'leave', 'half_day', 'holiday'];

    public function sheet(Request $request, BranchContext $branches): JsonResponse
    {
        $date = $request->validate(['date' => ['required', 'date_format:Y-m-d']])['date'];
        $allowed = $branches->allowedBranchIds();
        $active = $branches->activeBranchId();

        $employees = Employee::query()
            ->where('join_date', '<=', $date)
            ->where(fn ($q) => $q->whereNull('leave_date')->orWhere('leave_date', '>=', $date))
            ->where('status', 'active')
            ->when($active !== null, fn ($q) => $q->where('branch_id', $active))
            ->when($active === null && $allowed !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhereIn('branch_id', $allowed)))
            ->orderBy('code')->get(['id', 'code', 'name', 'designation', 'department', 'branch_id']);

        $marked = Attendance::query()->where('date', $date)->whereIn('employee_id', $employees->pluck('id'))->get()->keyBy('employee_id');

        return new JsonResponse(['data' => $employees->map(fn (Employee $e): array => [
            'employee_id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'designation' => $e->designation, 'department' => $e->department,
            'status' => $marked[$e->id]->status ?? null,
            'overtime_hours' => $marked[$e->id]->overtime_hours ?? '0',
            'note' => $marked[$e->id]->note ?? null,
        ])->values()]);
    }

    public function save(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'rows' => ['required', 'array', 'min:1', 'max:2000'],
            'rows.*.employee_id' => ['required', 'uuid', 'distinct', TenantRule::exists('employees')],
            'rows.*.status' => ['required', 'in:'.implode(',', self::STATUSES)],
            'rows.*.overtime_hours' => ['nullable', 'numeric', 'min:0', 'max:24'],
            'rows.*.note' => ['nullable', 'string', 'max:200'],
        ]);

        DB::transaction(function () use ($data, $request): void {
            foreach ($data['rows'] as $row) {
                Attendance::query()->updateOrCreate(
                    ['employee_id' => $row['employee_id'], 'date' => $data['date']],
                    ['status' => $row['status'], 'overtime_hours' => $row['overtime_hours'] ?? 0, 'note' => $row['note'] ?? null, 'recorded_by' => $request->user()->id],
                );
            }
        });

        return new JsonResponse(['data' => ['saved' => count($data['rows'])]]);
    }

    /** Month summary per employee: days by status and overtime. */
    public function summary(Request $request): JsonResponse
    {
        $period = $request->validate(['period' => ['required', 'date_format:Y-m']])['period'];

        $rows = DB::table('attendance')->join('employees', 'employees.id', '=', 'attendance.employee_id')
            ->where('attendance.tenant_id', app(\Shared\Domain\Contract\TenantContext::class)->requireId())
            ->whereRaw("to_char(attendance.date, 'YYYY-MM') = ?", [$period])
            ->groupBy('employees.id', 'employees.code', 'employees.name')
            ->selectRaw("employees.id as employee_id, employees.code, employees.name,
                sum(case when status = 'present' then 1 else 0 end) as present,
                sum(case when status = 'absent' then 1 else 0 end) as absent,
                sum(case when status = 'leave' then 1 else 0 end) as leave,
                sum(case when status = 'half_day' then 1 else 0 end) as half_day,
                sum(case when status = 'holiday' then 1 else 0 end) as holiday,
                sum(overtime_hours) as overtime_hours")
            ->orderBy('employees.code')->get();

        return new JsonResponse(['data' => $rows]);
    }
}
