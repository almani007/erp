<?php

declare(strict_types=1);

namespace Purchasing\Presentation\Http\Controller;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Organization\Domain\Contract\BranchContext;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Purchasing\Application\RecordPurchaseReturn;
use Purchasing\Application\RecordSupplierBill;
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceipt;
use Purchasing\Infrastructure\Persistence\Eloquent\PurchaseReturn;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierBill;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Shared\Infrastructure\Validation\TenantRule;

/** Supplier bills (three-way match), debit notes, and what we owe: aging and statements. */
final class PayablesController
{
    public function __construct(private readonly TenantContext $tenant) {}

    // ── Bills ────────────────────────────────────────────────────

    public function bills(): JsonResponse
    {
        return new JsonResponse(['data' => SupplierBill::query()->with('supplier:id,name,supplier_code')
            ->orderByDesc('bill_date')->orderByDesc('created_at')->limit(2000)->get()]);
    }

    public function bill(string $id): JsonResponse
    {
        return new JsonResponse(['data' => SupplierBill::query()
            ->with(['lines', 'receipts:id,supplier_bill_id,grn_no,grand_total,returned_total,received_at', 'supplier:id,name,supplier_code,phone,address,tax_no'])
            ->findOrFail($id)]);
    }

    /** Receipts from this supplier still waiting for the supplier's invoice. */
    public function unbilledReceipts(string $supplierId): JsonResponse
    {
        return new JsonResponse(['data' => GoodsReceipt::query()->where('supplier_id', $supplierId)->whereNull('supplier_bill_id')
            ->orderBy('received_at')->get(['id', 'grn_no', 'purchase_order_id', 'received_at', 'grand_total', 'returned_total', 'supplier_invoice_no'])]);
    }

    public function storeBill(Request $request, RecordSupplierBill $action, BranchContext $branches): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'uuid', TenantRule::exists('suppliers')],
            'company_id' => ['nullable', 'uuid', TenantRule::exists('companies')],
            'supplier_invoice_no' => ['required', 'string', 'max:80'],
            'bill_date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:bill_date'],
            'goods_receipt_ids' => ['present', 'array'],
            'goods_receipt_ids.*' => ['uuid', 'distinct'],
            'invoice_goods_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'expenses' => ['present', 'array', 'max:50'],
            'expenses.*.account_id' => ['required', 'uuid', TenantRule::exists('chart_of_accounts')],
            'expenses.*.description' => ['required', 'string', 'max:300'],
            'expenses.*.amount' => ['required', 'numeric', 'gt:0', 'decimal:0,2'],
            'expenses.*.tax_amount' => ['nullable', 'numeric', 'min:0', 'decimal:0,2'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $companyId = $data['company_id'] ?? Company::query()->orderBy('created_at')->value('id');
        $data['invoice_goods_amount'] = isset($data['invoice_goods_amount']) ? (string) $data['invoice_goods_amount'] : null;
        $data['expenses'] = array_map(static fn (array $e): array => ['amount' => (string) $e['amount'], 'tax_amount' => (string) ($e['tax_amount'] ?? '0')] + $e, $data['expenses']);

        $bill = $action->execute($data['supplier_id'], $companyId, $branches->activeBranchId(), $data,
            $request->user()->can('purchasing.bill.override'), $request->user()->id);

        return new JsonResponse(['data' => $bill], 201);
    }

    // ── Debit notes ──────────────────────────────────────────────

    public function returns(): JsonResponse
    {
        return new JsonResponse(['data' => PurchaseReturn::query()->with('supplier:id,name,supplier_code')
            ->orderByDesc('returned_at')->limit(2000)->get()]);
    }

    /** Receipt lines with what can still be sent back. */
    public function receiptForReturn(string $receiptId): JsonResponse
    {
        $grn = GoodsReceipt::query()->findOrFail($receiptId);
        $items = DB::table('goods_receipt_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->where('i.goods_receipt_id', $grn->id)
            ->get(['i.id', 'p.sku', 'p.name', 'i.quantity', 'i.returned_quantity', 'i.unit_cost', 'i.batch_no']);

        return new JsonResponse(['data' => ['receipt' => $grn->only(['id', 'grn_no', 'supplier_id', 'received_at', 'grand_total']), 'items' => $items]]);
    }

    public function receipts(Request $request): JsonResponse
    {
        $supplierId = $request->validate(['supplier_id' => ['required', 'uuid']])['supplier_id'];

        return new JsonResponse(['data' => GoodsReceipt::query()->where('supplier_id', $supplierId)
            ->orderByDesc('received_at')->limit(200)->get(['id', 'grn_no', 'received_at', 'grand_total', 'returned_total'])]);
    }

    public function storeReturn(Request $request, RecordPurchaseReturn $action): JsonResponse
    {
        $data = $request->validate([
            'goods_receipt_id' => ['required', 'uuid', TenantRule::exists('goods_receipts')],
            'reason' => ['required', 'string', 'max:300'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.goods_receipt_item_id' => ['required', 'uuid', 'distinct'],
            'lines.*.quantity' => ['required', 'numeric', 'min:0', 'decimal:0,6'],
        ]);
        $lines = array_map(static fn (array $l): array => ['goods_receipt_item_id' => $l['goods_receipt_item_id'], 'quantity' => (string) $l['quantity']], $data['lines']);

        return new JsonResponse(['data' => $action->execute($data['goods_receipt_id'], $lines, $data['reason'], $request->user()->id)], 201);
    }

    // ── What we owe ──────────────────────────────────────────────

    public function aging(Request $request): JsonResponse
    {
        $asOf = $request->validate(['as_of' => ['nullable', 'date_format:Y-m-d']])['as_of'] ?? today()->toDateString();
        $tenant = $this->tenant->requireId();

        $bills = DB::table('supplier_bills')->where('tenant_id', $tenant)->where('balance_due', '>', 0)
            ->selectRaw('supplier_id, balance_due, (?::date - due_date) as days_over', [$asOf])->get()->groupBy('supplier_id');
        $unbilled = DB::table('goods_receipts')->where('tenant_id', $tenant)->whereNull('supplier_bill_id')
            ->groupBy('supplier_id')->selectRaw('supplier_id, sum(grand_total - returned_total) as amount')->pluck('amount', 'supplier_id');

        $suppliers = Supplier::query()->where(fn ($q) => $q->where('balance', '!=', 0)->orWhereIn('id', $bills->keys()))
            ->orderBy('name')->get(['id', 'supplier_code', 'name', 'phone', 'balance', 'payment_terms_days']);

        $rows = $suppliers->map(function (Supplier $s) use ($bills, $unbilled): array {
            $b = ['current' => '0', 'd1_30' => '0', 'd31_60' => '0', 'd61_90' => '0', 'd90_plus' => '0'];
            $billed = '0';
            foreach ($bills[$s->id] ?? [] as $bill) {
                $key = match (true) {
                    $bill->days_over <= 0 => 'current', $bill->days_over <= 30 => 'd1_30', $bill->days_over <= 60 => 'd31_60',
                    $bill->days_over <= 90 => 'd61_90', default => 'd90_plus',
                };
                $b[$key] = Decimal::add($b[$key], (string) $bill->balance_due, 2);
                $billed = Decimal::add($billed, (string) $bill->balance_due, 2);
            }
            $balance = Decimal::round((string) $s->balance, 2);

            return [
                'supplier_id' => $s->id, 'supplier_code' => $s->supplier_code, 'name' => $s->name, 'phone' => $s->phone, 'balance' => $balance,
                'not_billed' => Decimal::round((string) ($unbilled[$s->id] ?? '0'), 2),
                // Payments not yet matched to bills show up here as a negative figure.
                'other' => Decimal::sub(Decimal::sub($balance, $billed, 2), Decimal::round((string) ($unbilled[$s->id] ?? '0'), 2), 2),
                'overdue' => Decimal::sub($billed, $b['current'], 2),
            ] + $b;
        })->values();

        return new JsonResponse(['data' => $rows, 'meta' => ['as_of' => $asOf]]);
    }

    public function openBills(string $supplierId): JsonResponse
    {
        return new JsonResponse(['data' => SupplierBill::query()->where('supplier_id', $supplierId)->where('balance_due', '>', 0)
            ->orderBy('due_date')->get(['id', 'bill_no', 'supplier_invoice_no', 'bill_date', 'due_date', 'grand_total', 'balance_due'])]);
    }

    /** Receipts of goods, bill differences and expenses, debit notes and payments, with a running balance. */
    public function statement(Request $request, string $supplierId): JsonResponse
    {
        $supplier = Supplier::query()->findOrFail($supplierId);
        $range = $request->validate(['from' => ['required', 'date_format:Y-m-d'], 'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from']]);
        $t = $this->tenant->requireId();

        $grn = DB::table('goods_receipts')->where('tenant_id', $t)->where('supplier_id', $supplierId)
            ->selectRaw("received_at as at, grn_no as doc_no, 'Goods received' as kind, supplier_invoice_no as reference, 0 as debit, grand_total as credit");
        $bills = DB::table('supplier_bills')->where('tenant_id', $t)->where('supplier_id', $supplierId)
            ->whereRaw('(variance + expense_total + expense_tax) <> 0')
            ->selectRaw("bill_date::timestamptz as at, bill_no as doc_no, 'Bill' as kind, supplier_invoice_no as reference,
                GREATEST(-(variance + expense_total + expense_tax), 0) as debit, GREATEST(variance + expense_total + expense_tax, 0) as credit");
        $returns = DB::table('purchase_returns')->where('tenant_id', $t)->where('supplier_id', $supplierId)
            ->selectRaw("returned_at as at, return_no as doc_no, 'Debit note' as kind, reason as reference, grand_total as debit, 0 as credit");
        $payments = DB::table('supplier_payments')->where('tenant_id', $t)->where('supplier_id', $supplierId)
            ->selectRaw("paid_at as at, payment_no as doc_no, 'Payment' as kind, reference, amount as debit, 0 as credit");

        $entries = DB::query()->fromSub($grn->unionAll($bills)->unionAll($returns)->unionAll($payments), 'x')->orderBy('at')->orderBy('doc_no')->get();

        $opening = '0';
        $running = null;
        $rows = [];
        foreach ($entries as $e) {
            $date = substr((string) $e->at, 0, 10);
            $change = Decimal::sub((string) $e->credit, (string) $e->debit, 2); // positive = we owe more
            if ($date < $range['from']) {
                $opening = Decimal::add($opening, $change, 2);

                continue;
            }
            if ($date > $range['to']) {
                break;
            }
            $running = Decimal::add($running ?? $opening, $change, 2);
            $rows[] = ['date' => $date, 'doc_no' => $e->doc_no, 'kind' => $e->kind, 'reference' => $e->reference,
                'debit' => Decimal::round((string) $e->debit, 2), 'credit' => Decimal::round((string) $e->credit, 2), 'balance' => $running];
        }

        return new JsonResponse(['data' => [
            'supplier' => $supplier->only(['id', 'supplier_code', 'name', 'phone', 'address', 'tax_no', 'balance']),
            'from' => $range['from'], 'to' => $range['to'], 'opening' => $opening, 'closing' => $running ?? $opening, 'entries' => $rows,
        ]]);
    }
}
