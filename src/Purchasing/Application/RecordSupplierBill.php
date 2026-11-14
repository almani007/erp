<?php

declare(strict_types=1);

namespace Purchasing\Application;

use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Finance\Infrastructure\Persistence\Eloquent\Account;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Purchasing\Infrastructure\Persistence\Eloquent\GoodsReceipt;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Purchasing\Infrastructure\Persistence\Eloquent\SupplierBill;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\Settings;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Three-way match: the supplier's invoice is matched to the goods receipts
 * it covers (the receipts already matched the purchase order). Goods were
 * put on the supplier's account at receipt, so the bill only posts:
 *
 *   the difference between their invoice and what we received
 *       Dr/Cr Purchase price variance   Cr/Dr Accounts payable
 *   and any expense lines (freight, services, rent, utilities)
 *       Dr Expense, Input tax           Cr Accounts payable
 *
 * A difference beyond the tolerance setting (purchasing.variance_tolerance_percent,
 * default 2 %) needs the purchasing.bill.override permission.
 */
final class RecordSupplierBill
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
        private readonly Settings $settings,
    ) {}

    /**
     * @param  array{supplier_invoice_no: string, bill_date: string, due_date?: string|null, goods_receipt_ids: list<string>,
     *               invoice_goods_amount?: string|null, expenses: list<array{account_id: string, description: string, amount: string, tax_amount?: string|null}>, notes?: string|null}  $data
     */
    public function execute(string $supplierId, string $companyId, ?string $branchId, array $data, bool $mayOverride, string $actorId): SupplierBill
    {
        return DB::transaction(function () use ($supplierId, $companyId, $branchId, $data, $mayOverride, $actorId): SupplierBill {
            $supplier = Supplier::query()->lockForUpdate()->findOrFail($supplierId);

            if (SupplierBill::query()->where('supplier_id', $supplier->id)->where('supplier_invoice_no', $data['supplier_invoice_no'])->exists()) {
                throw new \DomainException("Invoice {$data['supplier_invoice_no']} from {$supplier->name} is already entered.");
            }

            $receipts = GoodsReceipt::query()->whereIn('id', $data['goods_receipt_ids'])->lockForUpdate()->get();
            if ($receipts->count() !== count($data['goods_receipt_ids'])) {
                throw new \DomainException('A selected goods receipt no longer exists.');
            }
            foreach ($receipts as $grn) {
                if ($grn->supplier_id !== $supplier->id || $grn->company_id !== $companyId) {
                    throw new \DomainException("Goods receipt {$grn->grn_no} is not from {$supplier->name}.");
                }
                if ($grn->supplier_bill_id !== null) {
                    throw new \DomainException("Goods receipt {$grn->grn_no} is already on another bill.");
                }
            }

            $received = '0';
            foreach ($receipts as $grn) {
                $received = Decimal::add($received, Decimal::sub((string) $grn->grand_total, (string) $grn->returned_total, 2), 2);
            }

            $invoiceGoods = isset($data['invoice_goods_amount']) && $data['invoice_goods_amount'] !== null
                ? Decimal::round($data['invoice_goods_amount'], 2) : $received;
            $variance = $receipts->isEmpty() ? '0' : Decimal::sub($invoiceGoods, $received, 2);

            if (! Decimal::isZero($variance) && ! $mayOverride) {
                $tolerance = (string) ($this->settings->get('purchasing', 'variance_tolerance_percent', $companyId) ?? '2');
                $allowed = Decimal::div(Decimal::mul($received, $tolerance, 6), '100', 2);
                $abs = Decimal::isNegative($variance) ? Decimal::negate($variance, 2) : $variance;
                if (Decimal::cmp($abs, $allowed) > 0) {
                    throw new \DomainException("The invoice differs from the goods received by {$variance} — more than the {$tolerance}% tolerance. A manager must approve it.");
                }
            }

            $expenseTotal = '0';
            $expenseTax = '0';
            foreach ($data['expenses'] as $line) {
                $expenseTotal = Decimal::add($expenseTotal, Decimal::round($line['amount'], 2), 2);
                $expenseTax = Decimal::add($expenseTax, Decimal::round($line['tax_amount'] ?? '0', 2), 2);
            }

            if ($receipts->isEmpty() && ! Decimal::isPositive($expenseTotal)) {
                throw new \DomainException('Select goods receipts or enter expense lines.');
            }

            $grand = Decimal::add(Decimal::add(Decimal::add($received, $variance, 2), $expenseTotal, 2), $expenseTax, 2);
            if (! Decimal::isPositive($grand)) {
                throw new \DomainException('The bill total must be greater than zero.');
            }

            $billDate = $data['bill_date'];
            $bill = SupplierBill::query()->create([
                'company_id' => $companyId,
                'branch_id' => $branchId,
                'supplier_id' => $supplier->id,
                'bill_no' => $this->numbers->next($this->tenant->requireId(), 'supplier_bill', $companyId, 'BILL'),
                'supplier_invoice_no' => $data['supplier_invoice_no'],
                'bill_date' => $billDate,
                'due_date' => $data['due_date'] ?? Carbon::parse($billDate)->addDays((int) $supplier->payment_terms_days)->toDateString(),
                'received_value' => $received,
                'variance' => $variance,
                'expense_total' => $expenseTotal,
                'expense_tax' => $expenseTax,
                'grand_total' => $grand,
                'balance_due' => $grand,
                'status' => 'open',
                'notes' => $data['notes'] ?? null,
                'created_by' => $actorId,
            ]);

            $journalLines = [];
            $payable = '0';
            if (! Decimal::isZero($variance)) {
                $ppv = $this->accounts->id($companyId, 'purchase_price_variance');
                $journalLines[] = Decimal::isPositive($variance)
                    ? JournalLineDraft::debit($ppv, $variance, $branchId, 'Invoice above goods received')
                    : JournalLineDraft::credit($ppv, Decimal::negate($variance, 2), $branchId, 'Invoice below goods received');
                $payable = Decimal::add($payable, $variance, 2);
            }
            foreach ($data['expenses'] as $line) {
                $account = Account::query()->where('company_id', $companyId)->where('status', 'active')->findOrFail($line['account_id']);
                $journalLines[] = JournalLineDraft::debit($account->id, Decimal::round($line['amount'], 2), $branchId, $line['description']);
                $bill->lines()->create([
                    'account_id' => $account->id, 'description' => $line['description'],
                    'amount' => Decimal::round($line['amount'], 2), 'tax_amount' => Decimal::round($line['tax_amount'] ?? '0', 2),
                ]);
            }
            if (Decimal::isPositive($expenseTax)) {
                $journalLines[] = JournalLineDraft::debit($this->accounts->id($companyId, 'purchase_tax_receivable'), $expenseTax, $branchId);
            }
            $payable = Decimal::add($payable, Decimal::add($expenseTotal, $expenseTax, 2), 2);

            if (! Decimal::isZero($payable)) {
                $ap = $this->accounts->id($companyId, 'accounts_payable');
                $journalLines[] = Decimal::isPositive($payable)
                    ? JournalLineDraft::credit($ap, $payable, $branchId, null, 'supplier', $supplier->id)
                    : JournalLineDraft::debit($ap, Decimal::negate($payable, 2), $branchId, null, 'supplier', $supplier->id);

                $journal = $this->postJournal->execute(new JournalDraft(
                    companyId: $companyId, postingDate: $billDate, sourceType: 'supplier_bill', sourceId: $bill->id,
                    description: "Bill {$bill->bill_no} — {$supplier->name} invoice {$bill->supplier_invoice_no}",
                    lines: $journalLines,
                ));
                $bill->update(['journal_entry_id' => $journal->id]);
                $supplier->forceFill(['balance' => Decimal::add((string) $supplier->balance, $payable)])->save();
            }

            GoodsReceipt::query()->whereIn('id', $receipts->pluck('id'))->update(['supplier_bill_id' => $bill->id, 'supplier_invoice_no' => $bill->supplier_invoice_no]);

            $this->audit->record('purchasing.bill.recorded', 'supplier_bill', $bill->id, null, [
                'bill_no' => $bill->bill_no, 'supplier_invoice_no' => $bill->supplier_invoice_no, 'total' => $grand, 'variance' => $variance,
            ]);

            return $bill->load(['lines', 'receipts:id,supplier_bill_id,grn_no,grand_total,returned_total,received_at', 'supplier:id,name,supplier_code']);
        });
    }
}
