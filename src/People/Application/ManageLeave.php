<?php

declare(strict_types=1);

namespace People\Application;

use Carbon\CarbonImmutable;
use Identity\Infrastructure\Provider\IdentityServiceProvider;
use Illuminate\Contracts\Auth\Factory as AuthFactory;
use Illuminate\Support\Facades\DB;
use People\Infrastructure\Persistence\Eloquent\Attendance;
use People\Infrastructure\Persistence\Eloquent\Employee;
use People\Infrastructure\Persistence\Eloquent\LeaveRequest;
use People\Infrastructure\Persistence\Eloquent\LeaveType;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Support\Decimal;
use Workflow\Application\Notifier;

/**
 * Leave: request → approve / reject → (cancel).
 *
 * Days are counted without public holidays and weekly off days
 * (setting payroll.weekly_off_days, e.g. ["sun"]). Paid types with an
 * allowance cannot go over the balance: allowance for the calendar year
 * plus what was carried forward from last year (up to the type's limit),
 * less leave already approved or waiting.
 *
 * Approving writes the days into the attendance register — "leave" for
 * paid types, "unpaid_leave" for unpaid ones — so payroll pays or deducts
 * them with no further work. Cancelling takes those days back out, unless
 * that month's payroll is already approved.
 */
final class ManageLeave
{
    private const string NOTE_PREFIX = 'Leave: ';

    public function __construct(
        private readonly Settings $settings,
        private readonly AuditTrail $audit,
        private readonly AuthFactory $auth,
        private readonly Notifier $notifier,
    ) {}

    /** @param  array{employee_id: string, leave_type_id: string, from_date: string, to_date: string, half_day?: bool, reason?: string|null}  $data */
    public function request(array $data): LeaveRequest
    {
        return DB::transaction(function () use ($data): LeaveRequest {
            $employee = Employee::query()->lockForUpdate()->findOrFail($data['employee_id']);
            $type = LeaveType::query()->where('status', 'active')->findOrFail($data['leave_type_id']);
            $from = CarbonImmutable::parse($data['from_date']);
            $to = CarbonImmutable::parse($data['to_date']);
            $half = (bool) ($data['half_day'] ?? false);

            if ($to < $from) {
                throw new \DomainException('The leave cannot end before it starts.');
            }
            if ($half && ! $from->equalTo($to)) {
                throw new \DomainException('A half day is for a single date.');
            }
            if ($half && ! $type->allow_half_day) {
                throw new \DomainException("{$type->name} cannot be taken as a half day.");
            }
            if ($from->diffInDays($to) > 366) {
                throw new \DomainException('A leave request can cover at most a year.');
            }

            $overlap = LeaveRequest::query()->where('employee_id', $employee->id)->whereIn('status', ['pending', 'approved'])
                ->where('from_date', '<=', $to->toDateString())->where('to_date', '>=', $from->toDateString())->exists();
            if ($overlap) {
                throw new \DomainException("{$employee->name} already has leave on some of these dates.");
            }

            $days = $half ? '0.5' : (string) count($this->workingDays($employee, $from, $to));
            if (! Decimal::isPositive($days)) {
                throw new \DomainException('These dates are all holidays or off days — no leave is needed.');
            }

            // Leave can cross into the next year; each year's balance is checked separately.
            if ($type->paid) {
                foreach ($this->daysByYear($employee, $from, $to, $half) as $year => $inYear) {
                    $balance = $this->balance($employee, $type, $year);
                    if ($balance['limited'] && Decimal::cmp($inYear, $balance['available']) > 0) {
                        throw new \DomainException("{$employee->name} has {$balance['available']} days of {$type->name} left in {$year}; this request needs {$inYear}.");
                    }
                }
            }

            $leave = LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type_id' => $type->id,
                'from_date' => $from->toDateString(),
                'to_date' => $to->toDateString(),
                'half_day' => $half,
                'days' => $days,
                'reason' => $data['reason'] ?? null,
                'status' => 'pending',
                'requested_by' => $this->auth->guard('sanctum')->id(),
            ]);

            $this->audit->record('people.leave.requested', 'leave_request', $leave->id, null, ['employee' => $employee->code, 'type' => $type->code, 'days' => $days]);
            $this->notifier->toRole('branch_manager', 'leave', "Leave request: {$employee->name}",
                "{$type->name}, {$days} day(s) from {$from->toDateString()}", '/people');

            return $leave->load(['employee:id,code,name', 'type:id,code,name,paid']);
        });
    }

    public function decide(string $leaveId, bool $approve, ?string $note): LeaveRequest
    {
        return DB::transaction(function () use ($leaveId, $approve, $note): LeaveRequest {
            $leave = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveId);
            if ($leave->status !== 'pending') {
                throw new \DomainException("This leave request is already {$leave->status}.");
            }

            $user = $this->auth->guard('sanctum')->user();
            $employee = Employee::query()->findOrFail($leave->employee_id);
            $isSelf = $employee->user_id !== null && $employee->user_id === $user?->getAuthIdentifier();
            if ($isSelf && ! $user->hasRole(IdentityServiceProvider::OWNER_ROLE)) {
                throw new \DomainException('You cannot approve your own leave.');
            }

            if ($approve) {
                $this->writeAttendance($leave, $employee);
            }

            $leave->update([
                'status' => $approve ? 'approved' : 'rejected',
                'decided_by' => $user?->getAuthIdentifier(),
                'decided_at' => now(),
                'decision_note' => $note,
            ]);

            $this->audit->record($approve ? 'people.leave.approved' : 'people.leave.rejected', 'leave_request', $leave->id, null, ['employee' => $employee->code, 'days' => (string) $leave->days], $note);
            if ($leave->requested_by !== null) {
                $this->notifier->toUser($leave->requested_by, 'leave', 'Leave '.($approve ? 'approved' : 'rejected').": {$employee->name}",
                    $leave->from_date->toDateString().' to '.$leave->to_date->toDateString().($note ? " — {$note}" : ''), '/people');
            }

            return $leave->refresh()->load(['employee:id,code,name', 'type:id,code,name,paid']);
        });
    }

    public function cancel(string $leaveId, ?string $reason): LeaveRequest
    {
        return DB::transaction(function () use ($leaveId, $reason): LeaveRequest {
            $leave = LeaveRequest::query()->lockForUpdate()->findOrFail($leaveId);
            if (! in_array($leave->status, ['pending', 'approved'], true)) {
                throw new \DomainException("This leave request is already {$leave->status}.");
            }

            if ($leave->status === 'approved') {
                $employee = Employee::query()->findOrFail($leave->employee_id);
                $locked = DB::table('payroll_runs')->where('company_id', $employee->company_id)->whereIn('status', ['approved', 'paid'])
                    ->where('period_start', '<=', $leave->to_date->toDateString())->where('period_end', '>=', $leave->from_date->toDateString())
                    ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id))
                    ->exists();
                if ($locked) {
                    throw new \DomainException('Payroll for these dates is already approved, so this leave can no longer be cancelled.');
                }
                Attendance::query()->where('employee_id', $leave->employee_id)
                    ->whereBetween('date', [$leave->from_date->toDateString(), $leave->to_date->toDateString()])
                    ->where('note', 'like', self::NOTE_PREFIX.'%')
                    ->delete();
            }

            $leave->update(['status' => 'cancelled', 'decision_note' => $reason ?? $leave->decision_note]);
            $this->audit->record('people.leave.cancelled', 'leave_request', $leave->id, null, ['days' => (string) $leave->days], $reason);

            return $leave->refresh()->load(['employee:id,code,name', 'type:id,code,name,paid']);
        });
    }

    /**
     * @return array{type_id: string, code: string, name: string, paid: bool, limited: bool, entitled: string, carried: string, taken: string, pending: string, available: string}
     */
    public function balance(Employee $employee, LeaveType $type, int $year): array
    {
        $entitled = $this->entitlement($employee, $type, $year);
        $limited = $type->paid && Decimal::isPositive($entitled);

        $carried = '0';
        $max = (string) $type->carry_forward_max;
        if ($limited && Decimal::isPositive($max) && CarbonImmutable::parse($employee->join_date)->year < $year) {
            $lastYearLeft = Decimal::sub($this->entitlement($employee, $type, $year - 1), $this->used($employee, $type, $year - 1, ['approved']), 2);
            $carried = Decimal::isPositive($lastYearLeft) ? (Decimal::cmp($lastYearLeft, $max) > 0 ? $max : $lastYearLeft) : '0';
        }

        $taken = $this->used($employee, $type, $year, ['approved']);
        $pending = $this->used($employee, $type, $year, ['pending']);
        $available = Decimal::sub(Decimal::sub(Decimal::add($entitled, $carried, 2), $taken, 2), $pending, 2);

        return [
            'type_id' => $type->id, 'code' => $type->code, 'name' => $type->name, 'paid' => (bool) $type->paid, 'limited' => $limited,
            'entitled' => $entitled, 'carried' => $carried, 'taken' => $taken, 'pending' => $pending,
            'available' => $limited ? (Decimal::isNegative($available) ? '0' : $available) : '0',
        ];
    }

    /** @return list<array<string, mixed>> */
    public function balances(Employee $employee, int $year): array
    {
        return LeaveType::query()->where('status', 'active')->orderBy('code')->get()
            ->map(fn (LeaveType $t): array => $this->balance($employee, $t, $year))->all();
    }

    /** Dates between from and to that are neither holidays nor weekly off days for this employee. @return list<string> */
    public function workingDays(Employee $employee, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $holidays = DB::table('holidays')->whereBetween('date', [$from->toDateString(), $to->toDateString()])
            ->where(fn ($q) => $q->whereNull('branch_id')->orWhere('branch_id', $employee->branch_id))
            ->pluck('date')->map(static fn ($d): string => substr((string) $d, 0, 10))->flip();
        $off = array_map(static fn ($d): string => strtolower(substr((string) $d, 0, 3)),
            (array) ($this->settings->get('payroll', 'weekly_off_days', $employee->company_id) ?? []));

        $days = [];
        for ($d = $from; $d <= $to; $d = $d->addDay()) {
            if (! isset($holidays[$d->toDateString()]) && ! in_array(strtolower($d->format('D')), $off, true)) {
                $days[] = $d->toDateString();
            }
        }

        return $days;
    }

    private function writeAttendance(LeaveRequest $leave, Employee $employee): void
    {
        $type = LeaveType::query()->findOrFail($leave->leave_type_id);
        $from = CarbonImmutable::parse($leave->from_date);
        $to = CarbonImmutable::parse($leave->to_date);
        $status = $type->paid ? 'leave' : ($leave->half_day ? 'half_day' : 'unpaid_leave');
        $actor = $this->auth->guard('sanctum')->id();

        foreach ($this->workingDays($employee, $from, $to) as $date) {
            Attendance::query()->updateOrCreate(
                ['employee_id' => $employee->id, 'date' => $date],
                ['status' => $status, 'overtime_hours' => 0, 'note' => self::NOTE_PREFIX.$type->name.($leave->half_day ? ' (half day)' : ''), 'recorded_by' => $actor],
            );
        }
    }

    private function entitlement(Employee $employee, LeaveType $type, int $year): string
    {
        if (! $type->paid) {
            return '0';
        }
        $days = $type->use_employee_days ? (string) $employee->annual_leave_days : (string) $type->days_per_year;

        // Joined during the year: a share of the allowance for the months left.
        $joined = CarbonImmutable::parse($employee->join_date);
        if ($joined->year === $year && Decimal::isPositive($days)) {
            $months = 12 - $joined->month + 1;
            $days = Decimal::round(Decimal::div(Decimal::mul($days, (string) $months, 4), '12', 4), 1);
        }

        return Decimal::round($days, 2);
    }

    /** @param  list<string>  $statuses */
    private function used(Employee $employee, LeaveType $type, int $year, array $statuses): string
    {
        $rows = LeaveRequest::query()->where('employee_id', $employee->id)->where('leave_type_id', $type->id)
            ->whereIn('status', $statuses)
            ->where('from_date', '<=', "{$year}-12-31")->where('to_date', '>=', "{$year}-01-01")
            ->get(['from_date', 'to_date', 'half_day', 'days']);

        $total = '0';
        foreach ($rows as $r) {
            $inYear = $this->daysByYear($employee, CarbonImmutable::parse($r->from_date), CarbonImmutable::parse($r->to_date), (bool) $r->half_day)[$year] ?? '0';
            $total = Decimal::add($total, $inYear, 2);
        }

        return $total;
    }

    /** @return array<int, string> year => leave days in that year */
    private function daysByYear(Employee $employee, CarbonImmutable $from, CarbonImmutable $to, bool $half): array
    {
        if ($half) {
            return [$from->year => '0.5'];
        }
        $out = [];
        foreach ($this->workingDays($employee, $from, $to) as $date) {
            $y = (int) substr($date, 0, 4);
            $out[$y] = Decimal::add($out[$y] ?? '0', '1', 2);
        }

        return $out;
    }
}
