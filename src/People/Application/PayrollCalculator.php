<?php

declare(strict_types=1);

namespace People\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use People\Infrastructure\Persistence\Eloquent\Employee;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Support\Decimal;

/**
 * Works out one employee's pay for a period.
 *
 * Monthly staff: full salary and allowances, less a day's pay for each
 * absent day (and for days before joining / after leaving). A day's pay is
 * (salary + allowances) ÷ the month-days setting (30 by default).
 * Daily wagers: daily rate × days worked (present, paid leave, holiday; half day = ½).
 * Unpaid leave counts as an absent day for both.
 * Overtime: hourly rate × hours × the overtime-rate setting.
 *
 * The rules are settings (group `payroll`): month_days, hours_per_day,
 * overtime_rate. Tax follows the employee's tax method: the monthly
 * amount as entered (fixed), worked out from the tax slabs (slabs — see
 * IncomeTax), or none. An amount typed on the payroll line always wins.
 */
final class PayrollCalculator
{
    public function __construct(private readonly Settings $settings, private readonly IncomeTax $incomeTax) {}

    /**
     * @param  array{absent_days?: string|null, overtime_hours?: string|null, bonus?: string|null, other_deduction?: string|null, tax?: string|null, advance_recovery?: string|null}  $overrides
     * @return array<string, mixed> payroll_lines columns
     */
    public function calculate(Employee $employee, CarbonImmutable $start, CarbonImmutable $end, array $overrides = []): array
    {
        $monthDays = (string) ($this->settings->get('payroll', 'month_days', $employee->company_id) ?? '30');
        $hoursPerDay = (string) ($this->settings->get('payroll', 'hours_per_day', $employee->company_id) ?? '8');
        $overtimeRate = (string) ($this->settings->get('payroll', 'overtime_rate', $employee->company_id) ?? '1');

        $attendance = DB::table('attendance')->where('employee_id', $employee->id)
            ->whereBetween('date', [$start->toDateString(), $end->toDateString()])
            ->get(['status', 'overtime_hours']);

        $absentFromRegister = '0';
        $workedFromRegister = '0';
        foreach ($attendance as $day) {
            $absentFromRegister = Decimal::add($absentFromRegister, match ($day->status) { 'absent', 'unpaid_leave' => '1', 'half_day' => '0.5', default => '0' }, 2);
            $workedFromRegister = Decimal::add($workedFromRegister, match ($day->status) { 'absent', 'unpaid_leave' => '0', 'half_day' => '0.5', default => '1' }, 2);
        }
        $overtimeFromRegister = Decimal::sum($attendance, 'overtime_hours', 2);

        // Days outside employment count as unpaid for monthly staff.
        $employedFrom = max($start, CarbonImmutable::parse($employee->join_date));
        $employedTo = $employee->leave_date !== null ? min($end, CarbonImmutable::parse($employee->leave_date)) : $end;
        $periodDays = $start->diffInDays($end) + 1;
        $employedDays = $employedTo < $employedFrom ? 0 : $employedFrom->diffInDays($employedTo) + 1;
        $notEmployed = (string) max(0, $periodDays - $employedDays);

        $absent = self::val($overrides['absent_days'] ?? null) ?? $absentFromRegister;
        $overtimeHours = self::val($overrides['overtime_hours'] ?? null) ?? $overtimeFromRegister;
        $bonus = self::val($overrides['bonus'] ?? null) ?? '0';
        $other = self::val($overrides['other_deduction'] ?? null) ?? '0';
        $taxOverride = self::val($overrides['tax'] ?? null);

        $allowanceList = self::named($employee->allowances);
        $deductionList = self::named($employee->deductions);
        $allowances = Decimal::sum($allowanceList, 'amount', 2);
        $fixedDeductions = Decimal::sum($deductionList, 'amount', 2);
        $rate = (string) $employee->basic_salary;

        if ($employee->pay_type === 'daily') {
            $payableDays = $workedFromRegister;
            $basic = Decimal::round(Decimal::mul($rate, $payableDays, 4), 2);
            $allowances = Decimal::round(Decimal::div(Decimal::mul($allowances, $payableDays, 6), $monthDays, 6), 2);
            $absenceDeduction = '0';
            $hourly = Decimal::div($rate, $hoursPerDay, 6);
        } else {
            $unpaid = Decimal::add($absent, $notEmployed, 2);
            $payableDays = Decimal::sub($monthDays, $unpaid, 2);
            if (Decimal::isNegative($payableDays)) {
                $payableDays = '0';
            }
            $basic = Decimal::round($rate, 2);
            $dayRate = Decimal::div(Decimal::add($rate, $allowances, 6), $monthDays, 6);
            $absenceDeduction = Decimal::round(Decimal::mul($dayRate, $unpaid, 6), 2);
            if (Decimal::cmp($absenceDeduction, Decimal::add($basic, $allowances, 2)) > 0) {
                $absenceDeduction = Decimal::add($basic, $allowances, 2);
            }
            $hourly = Decimal::div(Decimal::div($rate, $monthDays, 6), $hoursPerDay, 6);
        }

        $overtime = Decimal::round(Decimal::mul(Decimal::mul($hourly, $overtimeHours, 6), $overtimeRate, 6), 2);
        $gross = Decimal::sub(Decimal::add(Decimal::add(Decimal::add($basic, $allowances, 2), $overtime, 2), $bonus, 2), $absenceDeduction, 2);
        $tax = $taxOverride ?? match ($employee->tax_method ?? 'fixed') {
            'none' => '0',
            'slabs' => Decimal::isPositive($gross) ? $this->incomeTax->monthly($employee, $start, $gross) : '0',
            default => (string) $employee->monthly_tax,
        };
        $beforeAdvances = Decimal::sub(Decimal::sub(Decimal::sub($gross, $fixedDeductions, 2), $tax, 2), $other, 2);

        // Advances are recovered oldest first, never taking pay below zero.
        [$recovery, $recoveredFrom] = $this->advanceRecovery($employee, self::val($overrides['advance_recovery'] ?? null), $beforeAdvances);

        return [
            'employee_id' => $employee->id,
            'payable_days' => $payableDays,
            'absent_days' => $absent,
            'overtime_hours' => $overtimeHours,
            'basic' => $basic,
            'allowances' => $allowances,
            'overtime_amount' => $overtime,
            'bonus' => $bonus,
            'absence_deduction' => $absenceDeduction,
            'gross' => $gross,
            'fixed_deductions' => $fixedDeductions,
            'tax' => Decimal::round($tax, 2),
            'other_deduction' => $other,
            'advance_recovery' => $recovery,
            'net' => Decimal::sub($beforeAdvances, $recovery, 2),
            'breakdown' => ['allowances' => $allowanceList, 'deductions' => $deductionList, 'advances' => $recoveredFrom],
        ];
    }

    /** @return array{0: string, 1: list<array{advance_id: string, advance_no: string, amount: string}>} */
    private function advanceRecovery(Employee $employee, ?string $requested, string $available): array
    {
        $open = DB::table('employee_advances')->where('employee_id', $employee->id)->where('status', 'open')
            ->orderBy('date')->orderBy('advance_no')->get(['id', 'advance_no', 'amount', 'installment', 'recovered']);

        $budget = $requested;
        $total = '0';
        $from = [];

        foreach ($open as $advance) {
            $outstanding = Decimal::sub((string) $advance->amount, (string) $advance->recovered, 2);
            $take = $requested === null ? (string) $advance->installment : $budget;
            $take = Decimal::cmp($take, $outstanding) > 0 ? $outstanding : $take;
            $room = Decimal::sub($available, $total, 2);
            $take = Decimal::cmp($take, $room) > 0 ? $room : $take;

            if (! Decimal::isPositive($take)) {
                continue;
            }
            $from[] = ['advance_id' => $advance->id, 'advance_no' => $advance->advance_no, 'amount' => Decimal::round($take, 2)];
            $total = Decimal::add($total, $take, 2);
            if ($requested !== null) {
                $budget = Decimal::sub($budget, $take, 2);
                if (! Decimal::isPositive($budget)) {
                    break;
                }
            }
        }

        return [$total, $from];
    }

    /** @return list<array{name: string, amount: string}> */
    private static function named(?array $rows): array
    {
        return array_values(array_map(
            static fn (array $r): array => ['name' => (string) ($r['name'] ?? ''), 'amount' => Decimal::round((string) ($r['amount'] ?? '0'), 2)],
            array_filter($rows ?? [], static fn ($r): bool => is_array($r) && is_numeric($r['amount'] ?? null)),
        ));
    }

    private static function val(mixed $v): ?string
    {
        return $v === null || $v === '' ? null : (string) $v;
    }
}
