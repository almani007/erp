<?php

declare(strict_types=1);

namespace Finance\Presentation\Http\Controller;

use Finance\Application\ExchangeRates;
use Finance\Application\RevalueForeignBalances;
use Finance\Infrastructure\Persistence\Eloquent\Currency;
use Finance\Infrastructure\Persistence\Eloquent\ExchangeRate;
use Finance\Infrastructure\Persistence\Eloquent\FxRevaluation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Shared\Infrastructure\Validation\TenantRule;

/** Foreign currencies, daily rates, foreign payables and period-end revaluation. */
final class CurrencyController
{
    public function index(Request $request, ExchangeRates $rates): JsonResponse
    {
        $company = $this->company($request);
        $today = now()->toDateString();

        return new JsonResponse(['data' => [
            'base_currency' => $rates->baseCurrency($company),
            'currencies' => Currency::query()->orderBy('code')->get()->map(fn (Currency $c): array => $c->toArray() + [
                'rate_today' => $rates->find($company, $c->code, $today),
                'rate_date' => ExchangeRate::query()->where('company_id', $company)->where('currency', $c->code)->where('rate_date', '<=', $today)->max('rate_date'),
            ]),
        ]]);
    }

    public function save(Request $request, ?string $id = null): JsonResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'size:3', 'alpha', TenantRule::unique('currencies', 'code', $id)],
            'name' => ['required', 'string', 'max:60'],
            'symbol' => ['nullable', 'string', 'max:8'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);
        $data['code'] = strtoupper($data['code']);
        $currency = $id === null ? Currency::query()->create($data) : tap(Currency::query()->findOrFail($id))->update($data);

        return new JsonResponse(['data' => $currency], $id === null ? 201 : 200);
    }

    public function rates(Request $request): JsonResponse
    {
        $data = $request->validate(['currency' => ['nullable', 'string', 'size:3'], 'company_id' => ['nullable', 'uuid']]);

        return new JsonResponse(['data' => ExchangeRate::query()->where('company_id', $this->company($request))
            ->when($data['currency'] ?? null, fn ($q, $c) => $q->where('currency', strtoupper($c)))
            ->orderByDesc('rate_date')->orderBy('currency')->limit(3000)->get()]);
    }

    /** Enter one or many rates; the same currency and date is overwritten. */
    public function storeRates(Request $request, ExchangeRates $fx): JsonResponse
    {
        $data = $request->validate([
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'rates' => ['required', 'array', 'min:1', 'max:500'],
            'rates.*.currency' => ['required', 'string', 'size:3', TenantRule::exists('currencies', 'code')],
            'rates.*.rate_date' => ['required', 'date_format:Y-m-d'],
            'rates.*.rate' => ['required', 'numeric', 'gt:0', 'decimal:0,8'],
            'rates.*.source' => ['nullable', 'string', 'max:40'],
        ]);
        $company = $data['company_id'] ?? $this->company($request);
        $base = $fx->baseCurrency($company);

        $saved = DB::transaction(fn (): array => array_map(function (array $r) use ($company, $base, $request): ExchangeRate {
            if (strtoupper($r['currency']) === $base) {
                throw new \DomainException("{$base} is the base currency; it needs no rate.");
            }

            return ExchangeRate::query()->updateOrCreate(
                ['company_id' => $company, 'currency' => strtoupper($r['currency']), 'rate_date' => $r['rate_date']],
                ['rate' => (string) $r['rate'], 'source' => $r['source'] ?? 'manual', 'created_by' => $request->user()->id],
            );
        }, $data['rates']));

        return new JsonResponse(['data' => $saved], 201);
    }

    /** Open foreign-currency bills, valued at their booked rate and at today's. */
    public function exposure(Request $request, RevalueForeignBalances $reval): JsonResponse
    {
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? now()->toDateString();
        $company = $this->company($request);

        $open = DB::table('supplier_bills as b')->join('suppliers as s', 's.id', '=', 'b.supplier_id')
            ->where('b.company_id', $company)->whereNotNull('b.currency')->where('b.balance_due', '>', 0)
            ->groupBy('b.currency')
            ->selectRaw('b.currency, count(*) as bills, sum(b.foreign_balance_due) as open_foreign, sum(b.balance_due) as carried')
            ->get();

        return new JsonResponse(['data' => ['as_of' => $asOf, 'by_currency' => $open, 'revaluation' => $reval->preview($company, $asOf)]]);
    }

    public function revaluations(Request $request): JsonResponse
    {
        return new JsonResponse(['data' => FxRevaluation::query()->where('company_id', $this->company($request))->orderByDesc('as_of')->get()]);
    }

    public function revalue(Request $request, RevalueForeignBalances $reval): JsonResponse
    {
        $data = $request->validate(['as_of' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')]]);

        return new JsonResponse(['data' => $reval->run($data['company_id'] ?? $this->company($request), $data['as_of'])], 201);
    }

    private function company(Request $request): string
    {
        $id = $request->input('company_id') ?? $request->query('company_id');

        return is_string($id) && Company::query()->whereKey($id)->exists()
            ? $id
            : (string) Company::query()->orderBy('created_at')->value('id');
    }
}
