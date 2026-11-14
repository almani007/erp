<?php

declare(strict_types=1);

namespace Sales\Application;

use Production\Application\RecipeConsumption;
use Catalog\Infrastructure\Persistence\Eloquent\Coupon;
use Catalog\Infrastructure\Persistence\Eloquent\Product;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Inventory\Domain\Enum\MovementType;
use Inventory\Domain\Repository\StockLedger;
use Inventory\Domain\StockPosting;
use Organization\Infrastructure\Persistence\Eloquent\Company;
use Sales\Domain\Enum\PaymentMethod;
use Sales\Domain\Exception\CreditLimitExceeded;
use Sales\Domain\Exception\ShiftNotOpen;
use Sales\Infrastructure\Persistence\Eloquent\Customer;
use Sales\Infrastructure\Persistence\Eloquent\PosShift;
use Sales\Infrastructure\Persistence\Eloquent\Sale;
use Sales\Infrastructure\Persistence\Eloquent\SaleItem;
use Sales\Infrastructure\Persistence\Eloquent\SaleTender;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\EventOutbox;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;
use Symfony\Component\Uid\Uuid;

/**
 * WF-SALE: one database transaction writes the sale, its tenders, the stock
 * issue and the journal. Either all of it exists or none of it does.
 *
 *   Dr Cash / Bank / Receivable      Cr Sales revenue, Sales tax payable
 *   Dr Cost of goods sold            Cr Inventory
 */
final class RecordSale
{
    public function __construct(
        private readonly PriceCart $cart,
        private readonly StockLedger $stock,
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly EventOutbox $outbox,
        private readonly LoyaltyProgram $loyalty,
    ) {}

    /**
     * @param  list<array{product_id: string, quantity: string|int|float, unit_price?: string|int|float|null, discount?: string|int|float|null}>  $lines
     * @param  list<array{method: string, amount: string|int|float, reference?: string|null}>  $tenders
     */
    public function execute(
        string $cashierId,
        array $lines,
        array $tenders,
        ?string $customerId,
        bool $mayOverridePrice,
        ?string $idempotencyKey,
        ?string $couponCode = null,
        ?string $priceTier = null,
    ): Sale {
        return DB::transaction(function () use ($cashierId, $lines, $tenders, $customerId, $mayOverridePrice, $idempotencyKey, $couponCode, $priceTier): Sale {
            // Locking the shift serialises this cashier's sales with closing the shift.
            $shift = PosShift::query()->where('cashier_id', $cashierId)->where('status', 'open')->lockForUpdate()->first()
                ?? throw ShiftNotOpen::forCashier();
            $terminal = $shift->terminal;
            $company = Company::query()->findOrFail($shift->company_id);

            $priced = $this->cart->price($lines, $mayOverridePrice, $shift->branch_id, $couponCode, $customerId, $priceTier);
            $grandTotal = $priced['grand_total'];

            if (! Decimal::isPositive($grandTotal)) {
                throw new \DomainException('The sale total must be greater than zero.');
            }

            [$tenderRows, $paid, $change, $onCredit, $byPoints] = $this->settle($tenders, $grandTotal);
            $customer = $this->chargeCustomer($customerId, $onCredit, $byPoints);

            $sale = Sale::query()->create([
                'company_id' => $company->id,
                'branch_id' => $shift->branch_id,
                'warehouse_id' => $terminal->warehouse_id,
                'terminal_id' => $terminal->id,
                'shift_id' => $shift->id,
                'cashier_id' => $cashierId,
                'customer_id' => $customer?->id,
                'sale_no' => $this->numbers->next($this->tenant->requireId(), 'sale', $shift->branch_id, 'INV'),
                'status' => Sale::COMPLETED,
                'currency' => $company->base_currency,
                'subtotal' => $priced['subtotal'],
                'discount_total' => $priced['discount_total'],
                'tax_total' => $priced['tax_total'],
                'grand_total' => $grandTotal,
                'paid_total' => $paid,
                'change_total' => $change,
                'cost_total' => '0',
                'idempotency_key' => $idempotencyKey,
                'coupon_code' => $priced['coupon']?->code,
                'price_tier' => $priced['price_tier'],
                'sold_at' => now(),
            ]);

            $this->redeemCoupon($priced['coupon']?->id);

            $costTotal = '0';

            foreach ($priced['lines'] as $line) {
                $product = $line['product'];
                $unitCost = '0';

                if ($product->product_type->tracksInventory()) {
                    $unitCost = $this->stock->issue(new StockPosting(
                        $company->id, $terminal->warehouse_id, $product->id, $line['base_quantity'],
                        MovementType::Sale, 'sale', $sale->id,
                    ));
                    $costTotal = Decimal::add($costTotal, Decimal::mul($line['base_quantity'], $unitCost, 2));
                } else {
                    // A recipe item (dish, drink): its ingredients leave stock instead.
                    $recipeCost = app(RecipeConsumption::class)->consume(
                        $company->id, $terminal->warehouse_id, $product->id, $line['base_quantity'], $sale->id,
                    );
                    if ($recipeCost !== null) {
                        $unitCost = $recipeCost;
                        $costTotal = Decimal::add($costTotal, Decimal::mul($line['base_quantity'], $unitCost, 2));
                    }
                }

                $saleItem = SaleItem::query()->create([
                    'sale_id' => $sale->id,
                    'product_id' => $product->id,
                    'sku' => $product->sku,
                    'product_name' => $product->name,
                    'quantity' => $line['quantity'],
                    'unit_price' => $line['unit_price'],
                    'discount_amount' => $line['discount'],
                    'tax_rate' => $line['tax_rate'],
                    'tax_amount' => $line['tax'],
                    'net_amount' => $line['net'],
                    'line_total' => $line['total'],
                    'cost_snapshot' => $unitCost,
                    'promotion_name' => $line['promotion_name'],
                    'unit_name' => $line['unit_name'],
                    'unit_factor' => $line['unit_factor'],
                ]);

                $this->recordSerials($sale, $saleItem, $product, $line, $customer?->id);
            }

            foreach ($tenderRows as $row) {
                SaleTender::query()->create(['sale_id' => $sale->id] + $row);
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $company->id,
                postingDate: now()->toDateString(),
                sourceType: 'sale',
                sourceId: $sale->id,
                description: "POS sale {$sale->sale_no}",
                lines: $this->journalLines($sale, $tenderRows, $change, $priced['net_total'], $priced['tax_total'], $costTotal, $customer),
            ));

            // Points: spend first, then earn on what was paid with real money.
            $redeemed = 0;
            $earned = 0;
            if ($customer !== null) {
                if (Decimal::isPositive($byPoints)) {
                    $redeemed = $this->loyalty->pointsToCover($byPoints);
                    $this->loyalty->move($customer, -$redeemed, 'redeem', 'sale', $sale->id);
                }
                $earned = $this->loyalty->pointsFor(Decimal::sub($grandTotal, $byPoints));
                $this->loyalty->move($customer, $earned, 'earn', 'sale', $sale->id);
            }

            $sale->update([
                'cost_total' => $costTotal, 'journal_entry_id' => $journal->id,
                'points_earned' => $earned, 'points_redeemed' => $redeemed,
            ]);

            $this->outbox->record('sales.sale.completed', 'sale', $sale->id, [
                'sale_no' => $sale->sale_no,
                'branch_id' => $sale->branch_id,
                'grand_total' => $grandTotal,
                'currency' => $sale->currency,
            ]);

            return $sale->load(['items', 'tenders', 'customer:id,name,customer_code']);
        });
    }

    /**
     * Products that track serials (phones, appliances, tools) must have one
     * serial / IMEI per unit sold; each is recorded with its warranty end date.
     *
     * @param  array<string, mixed>  $line  a priced cart line
     */
    private function recordSerials(Sale $sale, SaleItem $item, Product $product, array $line, ?string $customerId): void
    {
        if (! $product->track_serial) {
            return;
        }

        $serials = $line['serials'];

        if (Decimal::cmp((string) count($serials), $line['base_quantity']) !== 0) {
            throw new \DomainException("{$product->name}: enter one serial number for each unit sold (".count($serials).' entered).');
        }

        $taken = DB::table('sold_serials')->where('tenant_id', $this->tenant->requireId())
            ->where('product_id', $product->id)->whereIn('serial', $serials)->pluck('serial')->all();

        if ($taken !== []) {
            throw new \DomainException("{$product->name}: serial ".implode(', ', $taken).' is already recorded as sold.');
        }

        $months = (int) $product->warranty_months;

        foreach ($serials as $serial) {
            DB::table('sold_serials')->insert([
                'id' => Uuid::v7()->toRfc4122(),
                'tenant_id' => $this->tenant->requireId(),
                'product_id' => $product->id,
                'serial' => $serial,
                'sale_id' => $sale->id,
                'sale_item_id' => $item->id,
                'customer_id' => $customerId,
                'sold_at' => now(),
                'warranty_until' => $months > 0 ? now()->addMonths($months)->toDateString() : null,
            ]);
        }
    }

    /** Count one use of the coupon; the row lock keeps a limited coupon from being over-used. */
    private function redeemCoupon(?string $couponId): void
    {
        if ($couponId === null) {
            return;
        }

        $coupon = Coupon::query()->lockForUpdate()->findOrFail($couponId);

        if ($coupon->max_uses !== null && $coupon->used_count >= $coupon->max_uses) {
            throw new \DomainException('That coupon has just been used up.');
        }

        $coupon->increment('used_count');
    }

    /**
     * Validate the tenders against the total.
     *
     * @param  list<array{method: string, amount: string|int|float, reference?: string|null}>  $tenders
     * @return array{0: list<array{method: string, amount: string, external_reference: string|null}>, 1: string, 2: string, 3: string, 4: string}
     *                                                                                                                                         rows, paid, change, amount on credit, amount paid with points
     */
    private function settle(array $tenders, string $grandTotal): array
    {
        $rows = [];
        $paid = '0';
        $cash = '0';
        $onCredit = '0';
        $byPoints = '0';

        foreach ($tenders as $tender) {
            $method = PaymentMethod::from($tender['method']);
            $amount = Decimal::round((string) $tender['amount'], 2);

            if (! Decimal::isPositive($amount)) {
                throw new \DomainException('Tender amounts must be greater than zero.');
            }

            $rows[] = ['method' => $method->value, 'amount' => $amount, 'external_reference' => $tender['reference'] ?? null];
            $paid = Decimal::add($paid, $amount);

            if ($method === PaymentMethod::Cash) {
                $cash = Decimal::add($cash, $amount);
            }
            if ($method === PaymentMethod::Credit) {
                $onCredit = Decimal::add($onCredit, $amount);
            }
            if ($method === PaymentMethod::Loyalty) {
                $byPoints = Decimal::add($byPoints, $amount);
            }
        }

        if (Decimal::cmp($paid, $grandTotal) < 0) {
            throw new \DomainException('Payment is short by '.Decimal::sub($grandTotal, $paid, 2).'.');
        }

        // Change is handed back in cash, so it can never exceed the cash tendered.
        $change = Decimal::sub($paid, $grandTotal);
        if (Decimal::cmp($change, $cash) > 0) {
            throw new \DomainException('Non-cash payments exceed the sale total.');
        }

        return [$rows, $paid, $change, $onCredit, $byPoints];
    }

    private function chargeCustomer(?string $customerId, string $onCredit, string $byPoints): ?Customer
    {
        if ($customerId === null) {
            if (Decimal::isPositive($onCredit)) {
                throw new \DomainException('Select a customer to sell on credit.');
            }
            if (Decimal::isPositive($byPoints)) {
                throw new \DomainException('Select a customer to pay with loyalty points.');
            }

            return null;
        }

        $customer = Customer::query()->where('status', 'active')->lockForUpdate()->findOrFail($customerId);

        if (Decimal::isPositive($onCredit)) {
            $newBalance = Decimal::add((string) $customer->balance, $onCredit);

            if (Decimal::cmp($newBalance, (string) $customer->credit_limit) > 0) {
                throw CreditLimitExceeded::forCustomer($customer->name, (string) $customer->credit_limit, (string) $customer->balance, $onCredit);
            }

            $customer->forceFill(['balance' => $newBalance])->save();
        }

        return $customer;
    }

    /**
     * @param  list<array{method: string, amount: string, external_reference: string|null}>  $tenders
     * @return list<JournalLineDraft>
     */
    private function journalLines(Sale $sale, array $tenders, string $change, string $net, string $tax, string $cost, ?Customer $customer): array
    {
        $company = $sale->company_id;
        $branch = $sale->branch_id;

        // Money in, grouped by the account each tender lands in. Change leaves the cash drawer.
        $received = [];
        foreach ($tenders as $tender) {
            $key = PaymentMethod::from($tender['method'])->postingAccount();
            $received[$key] = Decimal::add($received[$key] ?? '0', $tender['amount']);
        }
        if (Decimal::isPositive($change)) {
            $received['cash'] = Decimal::sub($received['cash'], $change);
        }

        $lines = [];
        foreach ($received as $key => $amount) {
            $isReceivable = $key === 'accounts_receivable';
            $lines[] = JournalLineDraft::debit(
                $this->accounts->id($company, $key), $amount, $branch, null,
                $isReceivable ? 'customer' : null, $isReceivable ? $customer?->id : null,
            );
        }

        $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'sales_revenue'), $net, $branch);
        $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'sales_tax_payable'), $tax, $branch);
        $lines[] = JournalLineDraft::debit($this->accounts->id($company, 'cost_of_goods_sold'), $cost, $branch);
        $lines[] = JournalLineDraft::credit($this->accounts->id($company, 'inventory'), $cost, $branch);

        return $lines;
    }
}
