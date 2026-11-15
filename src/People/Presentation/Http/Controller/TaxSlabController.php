<?php

declare(strict_types=1);

namespace People\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use People\Application\IncomeTax;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Support\Decimal;

/** Salary tax slabs (a company setting) and a calculator to check them. */
final class TaxSlabController
{
    public function show(IncomeTax $tax): JsonResponse
    {
        return new JsonResponse(['data' => $tax->rules($this->company()) + ['defaults' => IncomeTax::DEFAULT_SLABS]]);
    }

    public function update(Request $request, Settings $settings): JsonResponse
    {
        $data = $request->validate([
            'slabs' => ['required', 'array', 'min:1', 'max:20'],
            'slabs.*.from' => ['required', 'numeric', 'min:0'],
            'slabs.*.to' => ['nullable', 'numeric', 'gt:slabs.*.from'],
            'slabs.*.fixed' => ['required', 'numeric', 'min:0'],
            'slabs.*.rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'year_start_month' => ['required', 'integer', 'min:1', 'max:12'],
            'surcharge_threshold' => ['required', 'numeric', 'min:0'],
            'surcharge_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        // Slabs must run in order with no gaps, and only the last may be open-ended.
        $slabs = collect($data['slabs'])->sortBy(fn ($s) => (float) $s['from'])->values()
            ->map(fn ($s) => ['from' => (string) $s['from'], 'to' => isset($s['to']) ? (string) $s['to'] : null, 'fixed' => (string) $s['fixed'], 'rate' => (string) $s['rate']])->all();
        foreach ($slabs as $i => $s) {
            $last = $i === count($slabs) - 1;
            if ($s['to'] === null && ! $last) {
                abort(422, 'Only the last slab can have no upper limit.');
            }
            if (! $last && Decimal::cmp($slabs[$i + 1]['from'], $s['to']) !== 0) {
                abort(422, "The slab after {$s['to']} must start at {$s['to']}.");
            }
        }

        $company = $this->company();
        $settings->set('payroll', 'tax_slabs', $slabs, $company);
        $settings->set('payroll', 'tax_year_start_month', $data['year_start_month'], $company);
        $settings->set('payroll', 'tax_surcharge_threshold', (string) $data['surcharge_threshold'], $company);
        $settings->set('payroll', 'tax_surcharge_rate', (string) $data['surcharge_rate'], $company);

        return $this->show(app(IncomeTax::class));
    }

    /** Tax on a yearly (or monthly × 12) income with the saved slabs. */
    public function calculate(Request $request, IncomeTax $tax): JsonResponse
    {
        $income = (string) $request->validate(['annual_income' => ['required', 'numeric', 'min:0']])['annual_income'];
        $annual = $tax->annual($income, $tax->rules($this->company()));

        return new JsonResponse(['data' => [
            'annual_income' => $income, 'annual_tax' => $annual, 'monthly_tax' => Decimal::round(Decimal::div($annual, '12', 6), 2),
            'effective_rate' => Decimal::isPositive($income) ? Decimal::round(Decimal::div(Decimal::mul($annual, '100', 6), $income, 6), 2) : '0',
        ]]);
    }

    private function company(): ?string
    {
        return Company::query()->orderBy('created_at')->value('id');
    }
}
