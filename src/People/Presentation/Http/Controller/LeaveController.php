<?php

declare(strict_types=1);

namespace People\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use People\Application\ManageLeave;
use People\Infrastructure\Persistence\Eloquent\Employee;
use People\Infrastructure\Persistence\Eloquent\Holiday;
use People\Infrastructure\Persistence\Eloquent\LeaveRequest;
use People\Infrastructure\Persistence\Eloquent\LeaveType;
use Shared\Infrastructure\Validation\TenantRule;

/**
 * Leave types, holidays, requests and balances.
 * Staff with only people.leave.request see and request their own leave
 * (their login must be linked to their employee record).
 */
final class LeaveController
{
    // ── Types ────────────────────────────────────────────────────

    public function types(): JsonResponse
    {
        return new JsonResponse(['data' => LeaveType::query()->orderBy('code')->get()]);
    }

    public function saveType(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:20', 'alpha_dash', TenantRule::unique('leave_types', 'code', $id)],
            'name' => ['required', 'string', 'max:80'],
            'paid' => ['required', 'boolean'],
            'days_per_year' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'use_employee_days' => ['sometimes', 'boolean'],
            'carry_forward_max' => ['nullable', 'numeric', 'min:0', 'max:366'],
            'allow_half_day' => ['sometimes', 'boolean'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $data['days_per_year'] ??= 0;
        $data['carry_forward_max'] ??= 0;

        $type = $id === null ? LeaveType::query()->create($data) : tap(LeaveType::query()->findOrFail($id))->update($data);

        return new JsonResponse(['data' => $type], $id === null ? 201 : 200);
    }

    // ── Holidays ─────────────────────────────────────────────────

    public function holidays(Request $request): JsonResponse
    {
        $year = (int) ($request->query('year') ?: now()->year);

        return new JsonResponse(['data' => Holiday::query()->whereYear('date', $year)->orderBy('date')->get()]);
    }

    public function storeHoliday(Request $request): JsonResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date', 'before:'.date('Y-m-d', strtotime($request->input('date', 'today').' +31 days'))],
            'name' => ['required', 'string', 'max:120'],
            'branch_id' => ['nullable', 'uuid', TenantRule::exists('branches')],
        ]);

        $created = [];
        $day = new \DateTimeImmutable($data['date']);
        $last = new \DateTimeImmutable($data['to_date'] ?? $data['date']);
        for (; $day <= $last; $day = $day->modify('+1 day')) {
            $created[] = Holiday::query()->firstOrCreate(
                ['date' => $day->format('Y-m-d'), 'branch_id' => $data['branch_id'] ?? null],
                ['name' => $data['name']],
            );
        }

        return new JsonResponse(['data' => $created], 201);
    }

    public function deleteHoliday(string $id): JsonResponse
    {
        Holiday::query()->findOrFail($id)->delete();

        return new JsonResponse(null, 204);
    }

    // ── Requests ─────────────────────────────────────────────────

    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $all = $user->can('people.leave.manage') || $user->can('people.leave.approve');
        $filters = $request->validate(['status' => ['nullable', 'in:pending,approved,rejected,cancelled'], 'year' => ['nullable', 'integer']]);

        $rows = LeaveRequest::query()->with(['employee:id,code,name,department,designation', 'type:id,code,name,paid'])
            ->when(! $all, fn ($q) => $q->whereIn('employee_id', Employee::query()->where('user_id', $user->id)->select('id')))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['year'] ?? null, fn ($q, $y) => $q->where('from_date', '<=', "{$y}-12-31")->where('to_date', '>=', "{$y}-01-01"))
            ->orderByDesc('from_date')->limit(3000)->get();

        return new JsonResponse(['data' => $rows]);
    }

    public function store(Request $request, ManageLeave $leave): JsonResponse
    {
        $data = $request->validate([
            'employee_id' => ['nullable', 'uuid', TenantRule::exists('employees')],
            'leave_type_id' => ['required', 'uuid', TenantRule::exists('leave_types')],
            'from_date' => ['required', 'date_format:Y-m-d'],
            'to_date' => ['required', 'date_format:Y-m-d'],
            'half_day' => ['sometimes', 'boolean'],
            'reason' => ['nullable', 'string', 'max:500'],
        ]);
        $data['employee_id'] = $this->employeeFor($request, $data['employee_id'] ?? null);

        return new JsonResponse(['data' => $leave->request($data)], 201);
    }

    public function decide(Request $request, string $id, ManageLeave $leave): JsonResponse
    {
        $data = $request->validate(['decision' => ['required', 'in:approve,reject'], 'note' => [Rule::requiredIf($request->input('decision') === 'reject'), 'nullable', 'string', 'max:300']]);

        return new JsonResponse(['data' => $leave->decide($id, $data['decision'] === 'approve', $data['note'] ?? null)]);
    }

    public function cancel(Request $request, string $id, ManageLeave $leave): JsonResponse
    {
        $row = LeaveRequest::query()->findOrFail($id);
        $user = $request->user();
        $own = Employee::query()->whereKey($row->employee_id)->where('user_id', $user->id)->exists();
        // Staff can withdraw their own request while it waits; approved leave is cancelled by a manager.
        if (! $user->can('people.leave.manage') && ! ($own && $row->status === 'pending')) {
            abort(403, 'Only a manager can cancel approved leave.');
        }

        return new JsonResponse(['data' => $leave->cancel($id, $request->validate(['reason' => ['nullable', 'string', 'max:300']])['reason'] ?? null)]);
    }

    public function balances(Request $request, ManageLeave $leave): JsonResponse
    {
        $data = $request->validate(['employee_id' => ['nullable', 'uuid', TenantRule::exists('employees')], 'year' => ['nullable', 'integer', 'min:2000', 'max:2100']]);
        $year = (int) ($data['year'] ?? now()->year);
        $employee = Employee::query()->findOrFail($this->employeeFor($request, $data['employee_id'] ?? null));

        return new JsonResponse(['data' => ['employee' => $employee->only(['id', 'code', 'name']), 'year' => $year, 'balances' => $leave->balances($employee, $year)]]);
    }

    /** Leave register: every active employee's balance for each type. */
    public function register(Request $request, ManageLeave $leave): JsonResponse
    {
        $year = (int) ($request->validate(['year' => ['nullable', 'integer', 'min:2000', 'max:2100']])['year'] ?? now()->year);
        $types = LeaveType::query()->where('status', 'active')->orderBy('code')->get();

        $rows = Employee::query()->where('status', 'active')->orderBy('code')->get()->map(function (Employee $e) use ($leave, $types, $year): array {
            $row = ['employee_id' => $e->id, 'code' => $e->code, 'name' => $e->name, 'department' => $e->department];
            foreach ($types as $t) {
                $b = $leave->balance($e, $t, $year);
                $row[$t->code.'_taken'] = $b['taken'];
                $row[$t->code.'_available'] = $b['limited'] ? $b['available'] : null;
            }

            return $row;
        });

        return new JsonResponse(['data' => ['types' => $types->map->only(['code', 'name', 'paid']), 'rows' => $rows]]);
    }

    /** Managers may act for anyone; everyone else only for the employee linked to their login. */
    private function employeeFor(Request $request, ?string $employeeId): string
    {
        $user = $request->user();
        $mine = Employee::query()->where('user_id', $user->id)->value('id');

        if ($employeeId !== null && $employeeId !== $mine) {
            abort_unless($user->can('people.leave.manage') || $user->can('people.leave.approve'), 403, 'You can only see and request your own leave.');

            return $employeeId;
        }
        abort_if($mine === null && $employeeId === null, 422, 'Your login is not linked to an employee record. Ask HR to link it.');

        return $employeeId ?? $mine;
    }
}
