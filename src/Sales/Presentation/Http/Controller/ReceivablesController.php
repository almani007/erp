<?php

declare(strict_types=1);

namespace Sales\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/** What customers owe: aging, open invoices and account statements. */
final class ReceivablesController
{
    public const array BUCKETS = ['current', 'd1_30', 'd31_60', 'd61_90', 'd90_plus'];

    public function __construct(private readonly TenantContext $tenant) {}

    /** Every customer with a balance, split by how overdue their invoices are. */
    public function aging(Request $request): JsonResponse
    {
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? today()->toDateString();

        $open = DB::table('sales')
            ->where('tenant_id', $this->tenant->requireId())->where('status', 'completed')->where('balance_due', '>', 0)
            ->selectRaw("customer_id, balance_due, (?::date - COALESCE(due_date, sold_at::date)) as days_over", [$asOf])
            ->get()->groupBy('customer_id');

        $customers = Customer::query()->where(fn ($q) => $q->where('balance', '!=', 0)->orWhereIn('id', $open->keys()))
            ->orderBy('name')->get(['id', 'customer_code', 'name', 'phone', 'credit_limit', 'balance', 'payment_days']);

        $rows = $customers->map(function (Customer $c) use ($open): array {
            $buckets = array_fill_keys(self::BUCKETS, '0');
            $invoiced = '0';
            foreach ($open[$c->id] ?? [] as $inv) {
                $key = match (true) {
                    $inv->days_over <= 0 => 'current',
                    $inv->days_over <= 30 => 'd1_30',
                    $inv->days_over <= 60 => 'd31_60',
                    $inv->days_over <= 90 => 'd61_90',
                    default => 'd90_plus',
                };
                $buckets[$key] = Decimal::add($buckets[$key], (string) $inv->balance_due, 2);
                $invoiced = Decimal::add($invoiced, (string) $inv->balance_due, 2);
            }

            return [
                'customer_id' => $c->id, 'customer_code' => $c->customer_code, 'name' => $c->name, 'phone' => $c->phone,
                'credit_limit' => (string) $c->credit_limit, 'balance' => Decimal::round((string) $c->balance, 2),
                // Owed but not tied to an invoice (old POS credit), or unapplied credit when negative.
                'not_by_invoice' => Decimal::sub(Decimal::round((string) $c->balance, 2), $invoiced, 2),
                'overdue' => Decimal::sub($invoiced, $buckets['current'], 2),
            ] + $buckets;
        })->values();

        return new JsonResponse(['data' => $rows, 'meta' => ['as_of' => $asOf]]);
    }

    public function openInvoices(string $customerId): JsonResponse
    {
        Customer::query()->findOrFail($customerId);

        return new JsonResponse(['data' => DB::table('sales')
            ->where('tenant_id', $this->tenant->requireId())->where('customer_id', $customerId)
            ->where('status', 'completed')->where('balance_due', '>', 0)
            ->orderByRaw('COALESCE(due_date, sold_at::date)')
            ->get(['id', 'sale_no', 'channel', 'sold_at', 'due_date', 'grand_total', 'balance_due', 'reference'])]);
    }

    /** Running account: credit sales and invoices, returns to account, receipts. */
    public function statement(Request $request, string $customerId): JsonResponse
    {
        $customer = Customer::query()->findOrFail($customerId);
        $range = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $tenant = $this->tenant->requireId();

        $credit = DB::table('sales as s')->join('sale_tenders as t', 't.sale_id', '=', 's.id')
            ->where('s.tenant_id', $tenant)->where('s.customer_id', $customerId)->where('s.status', 'completed')->where('t.method', 'credit')
            ->groupBy('s.id', 's.sale_no', 's.sold_at', 's.due_date', 's.channel', 's.reference')
            ->selectRaw("s.sold_at as at, s.sale_no as doc_no, CASE WHEN s.channel = 'invoice' THEN 'Invoice' ELSE 'Credit sale' END as kind,
                s.reference, s.due_date, sum(t.amount) as debit, 0 as credit");
        $returns = DB::table('sales_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')
            ->where('r.tenant_id', $tenant)->where('s.customer_id', $customerId)->where('r.refund_method', 'account')
            ->selectRaw("r.returned_at as at, r.return_no as doc_no, 'Return' as kind, s.sale_no as reference, null::date as due_date, 0 as debit, r.grand_total as credit");
        $payments = DB::table('customer_payments')->where('tenant_id', $tenant)->where('customer_id', $customerId)
            ->selectRaw("received_at as at, receipt_no as doc_no, 'Receipt' as kind, reference, null::date as due_date, 0 as debit, amount as credit");

        $all = $credit->unionAll($returns)->unionAll($payments);
        $entries = DB::query()->fromSub($all, 'x')->orderBy('at')->orderBy('doc_no')->get();

        $opening = '0';
        $rows = [];
        $running = null;
        foreach ($entries as $e) {
            $date = substr((string) $e->at, 0, 10);
            $change = Decimal::sub((string) $e->debit, (string) $e->credit, 2);
            if ($date < $range['from']) {
                $opening = Decimal::add($opening, $change, 2);

                continue;
            }
            if ($date > $range['to']) {
                break;
            }
            $running = Decimal::add($running ?? $opening, $change, 2);
            $rows[] = ['date' => $date, 'doc_no' => $e->doc_no, 'kind' => $e->kind, 'reference' => $e->reference, 'due_date' => $e->due_date,
                'debit' => Decimal::round((string) $e->debit, 2), 'credit' => Decimal::round((string) $e->credit, 2), 'balance' => $running];
        }

        return new JsonResponse(['data' => [
            'customer' => $customer->only(['id', 'customer_code', 'name', 'phone', 'email', 'tax_no', 'address', 'credit_limit', 'balance']),
            'from' => $range['from'], 'to' => $range['to'],
            'opening' => $opening, 'closing' => $running ?? $opening, 'entries' => $rows,
        ]]);
    }
}
