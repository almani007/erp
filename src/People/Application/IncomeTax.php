<?php

declare(strict_types=1);

namespace People\Application;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use People\Infrastructure\Persistence\Eloquent\Employee;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Support\Decimal;

/**
 * Salary income tax from slabs (setting payroll.tax_slabs).
 *
 * Each slab: annual income from, to (null = no upper end), fixed tax, and
 * the rate in % on the part above "from". The defaults are Pakistan's
 * salaried slabs for tax year 2025-26 (Finance Act 2025); each year's
 * Finance Act changes them, so they are a setting to keep up to date.
 *
 * Monthly withholding is the usual year-to-date method:
 *   projected income = taxable pay already run this tax year
 *                    + this month × months left (this one included)
 *   withholding      = (tax on projected income − tax already withheld)
 *                      ÷ months left
 * so a raise or bonus mid-year is spread over the rest of the year and the
 * year ends with the right total.
 */
final class IncomeTax
{
    /** @var list<array{from: string, to: string|null, fixed: string, rate: string}> */
    public const array DEFAULT_SLABS = [
        ['from' => '0', 'to' => '600000', 'fixed' => '0', 'rate' => '0'],
        ['from' => '600000', 'to' => '1200000', 'fixed' => '0', 'rate' => '1'],
        ['from' => '1200000', 'to' => '2200000', 'fixed' => '6000', 'rate' => '11'],
        ['from' => '2200000', 'to' => '3200000', 'fixed' => '116000', 'rate' => '23'],
        ['from' => '3200000', 'to' => '4100000', 'fixed' => '346000', 'rate' => '30'],
        ['from' => '4100000', 'to' => null, 'fixed' => '616000', 'rate' => '35'],
    ];

    public function __construct(private readonly Settings $settings) {}

    /** @return array{slabs: list<array<string, string|null>>, year_start_month: int, surcharge_threshold: string, surcharge_rate: string} */
    public function rules(?string $companyId): array
    {
        $slabs = $this->settings->get('payroll', 'tax_slabs', $companyId);

        return [
            'slabs' => is_array($slabs) && $slabs !== [] ? array_values($slabs) : self::DEFAULT_SLABS,
            'year_start_month' => (int) ($this->settings->get('payroll', 'tax_year_start_month', $companyId) ?? 7),
            // Surcharge on the tax when income is above the threshold (salaried: 9 % above 10 million).
            'surcharge_threshold' => (string) ($this->settings->get('payroll', 'tax_surcharge_threshold', $companyId) ?? '10000000'),
            'surcharge_rate' => (string) ($this->settings->get('payroll', 'tax_surcharge_rate', $companyId) ?? '9'),
        ];
    }

    /** Tax for a year's income. */
    public function annual(string $income, array $rules): string
    {
        $tax = '0';
        foreach ($rules['slabs'] as $slab) {
            $from = (string) $slab['from'];
            $to = isset($slab['to']) && $slab['to'] !== null && $slab['to'] !== '' ? (string) $slab['to'] : null;
            if (Decimal::cmp($income, $from) > 0 && ($to === null || Decimal::cmp($income, $to) <= 0)) {
                $tax = Decimal::add((string) $slab['fixed'], Decimal::div(Decimal::mul(Decimal::sub($income, $from, 2), (string) $slab['rate'], 6), '100', 6), 6);
                break;
            }
        }
        if (Decimal::isPositive($rules['surcharge_rate']) && Decimal::cmp($income, $rules['surcharge_threshold']) > 0) {
            $tax = Decimal::add($tax, Decimal::div(Decimal::mul($tax, $rules['surcharge_rate'], 6), '100', 6), 6);
        }

        return Decimal::round($tax, 2);
    }

    /** This month's withholding for an employee taxed by slabs. */
    public function monthly(Employee $employee, CarbonImmutable $periodStart, string $taxableThisMonth): string
    {
        $rules = $this->rules($employee->company_id);
        $startMonth = max(1, min(12, $rules['year_start_month']));
        $yearStart = CarbonImmutable::create($periodStart->month >= $startMonth ? $periodStart->year : $periodStart->year - 1, $startMonth, 1);
        $monthsLeft = 12 - (int) $yearStart->diffInMonths($periodStart->startOfMonth());

        $previous = DB::table('payroll_lines as l')->join('payroll_runs as r', 'r.id', '=', 'l.payroll_run_id')
            ->where('l.employee_id', $employee->id)->whereIn('r.status', ['approved', 'paid'])
            ->where('r.period_start', '>=', $yearStart->toDateString())->where('r.period_start', '<', $periodStart->toDateString())
            ->selectRaw('coalesce(sum(l.gross), 0) as gross, coalesce(sum(l.tax), 0) as tax')->first();

        $projected = Decimal::add((string) $previous->gross, Decimal::mul($taxableThisMonth, (string) $monthsLeft, 2), 2);
        $due = Decimal::sub($this->annual($projected, $rules), (string) $previous->tax, 2);

        return Decimal::isPositive($due) ? Decimal::round(Decimal::div($due, (string) max(1, $monthsLeft), 6), 2) : '0';
    }
}
