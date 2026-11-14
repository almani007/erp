<?php

declare(strict_types=1);

namespace Integration\Application;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Integration\Infrastructure\Persistence\Eloquent\FiscalSubmission;
use Shared\Domain\Support\Decimal;

/**
 * Reports sales to FBR and stores the fiscal invoice number for the receipt.
 *
 *  - POS sales  → FBR POS integration (Tier-1 retail; POSID + token), invoice type 1;
 *                 returns as type 3 with the original USIN.
 *  - Invoices to registered buyers → FBR Digital Invoicing (PRAL bearer token).
 *
 * A sale is never blocked by FBR being slow or down: the submission stays
 * pending and is retried with back-off by `integration:relay`.
 */
final class FbrReporter
{
    public const int MAX_ATTEMPTS = 12;

    public function __construct(private readonly FbrSettings $config) {}

    /** Queue a document if the branch reports to FBR. Returns null when it does not. */
    public function queue(string $documentType, string $documentId): ?FiscalSubmission
    {
        [$doc, $branchId, $no, $channel] = $this->document($documentType, $documentId);
        if ($doc === null) {
            return null;
        }
        $s = $this->config->forBranch($branchId);
        $system = match (true) {
            $channel === 'invoice' && (bool) $s['di_enabled'] => 'fbr_di',
            (bool) $s['pos_enabled'] => 'fbr_pos',
            default => null,
        };
        if ($system === null) {
            return null;
        }

        return FiscalSubmission::query()->firstOrCreate(
            ['system' => $system, 'document_type' => $documentType, 'document_id' => $documentId],
            ['branch_id' => $branchId, 'document_no' => $no, 'status' => 'pending', 'next_attempt_at' => now()],
        );
    }

    public function submit(FiscalSubmission $sub): FiscalSubmission
    {
        if ($sub->status === 'sent') {
            return $sub;
        }
        $s = $this->config->forBranch($sub->branch_id);

        try {
            [$url, $token, $payload] = $sub->system === 'fbr_di'
                ? [$this->config->diUrl($s), (string) $s['di_token'], $this->diPayload($sub, $s)]
                : [$this->config->posUrl($s), (string) $s['pos_token'], $this->posPayload($sub, $s)];
            if ($token === '') {
                throw new \RuntimeException('No FBR token is set for this branch.');
            }

            $response = Http::withToken($token)->acceptJson()->timeout(15)->post($url, $payload);
            $body = $response->json() ?? ['raw' => mb_substr($response->body(), 0, 2000)];

            $number = $sub->system === 'fbr_di'
                ? (($body['validationResponse']['statusCode'] ?? null) === '00' ? ($body['invoiceNumber'] ?? null) : null)
                : ((string) ($body['Code'] ?? '') === '100' ? ($body['InvoiceNumber'] ?? null) : null);

            if ($response->successful() && $number) {
                $sub->update(['status' => 'sent', 'fiscal_invoice_no' => $number, 'request' => $payload, 'response' => $body,
                    'sent_at' => now(), 'attempts' => $sub->attempts + 1, 'last_error' => null]);
                DB::table($sub->document_type === 'sale' ? 'sales' : 'sales_returns')->where('id', $sub->document_id)
                    ->update(['fiscal_invoice_no' => $number]);

                return $sub;
            }

            $error = $body['Response'] ?? $body['validationResponse']['error'] ?? $body['message'] ?? ('HTTP '.$response->status());
            $this->failed($sub, is_string($error) ? $error : json_encode($error), $payload, $body);
        } catch (\Throwable $e) {
            $this->failed($sub, $e->getMessage(), $payload ?? null, null);
        }

        return $sub;
    }

    /** @param  array<string, mixed>|null  $payload */
    private function failed(FiscalSubmission $sub, string $error, ?array $payload, mixed $body): void
    {
        $attempts = $sub->attempts + 1;
        $sub->update([
            'attempts' => $attempts, 'last_error' => mb_substr($error, 0, 1000), 'request' => $payload, 'response' => is_array($body) ? $body : null,
            'status' => $attempts >= self::MAX_ATTEMPTS ? 'failed' : 'pending',
            // 1, 2, 4 … minutes, at most an hour apart.
            'next_attempt_at' => now()->addMinutes(min(60, 2 ** min(6, $attempts - 1))),
        ]);
    }

    /** @return array{0: object|null, 1: string, 2: string, 3: string} */
    private function document(string $type, string $id): array
    {
        if ($type === 'sale') {
            $s = DB::table('sales')->where('id', $id)->first(['id', 'branch_id', 'sale_no', 'channel']);

            return [$s, (string) ($s->branch_id ?? ''), (string) ($s->sale_no ?? ''), (string) ($s->channel ?? 'pos')];
        }
        $r = DB::table('sales_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->where('r.id', $id)
            ->first(['r.id', 'r.branch_id', 'r.return_no', 's.channel']);

        return [$r, (string) ($r->branch_id ?? ''), (string) ($r->return_no ?? ''), (string) ($r->channel ?? 'pos')];
    }

    /** FBR POS integration payload (technical specification for Tier-1 retailers). */
    private function posPayload(FiscalSubmission $sub, array $s): array
    {
        $isReturn = $sub->document_type === 'sales_return';
        $header = $isReturn
            ? DB::table('sales_returns as r')->join('sales as s', 's.id', '=', 'r.sale_id')->where('r.id', $sub->document_id)
                ->first(['r.return_no as no', 'r.returned_at as at', 'r.net_total', 'r.tax_total', 'r.grand_total', DB::raw('0 as discount_total'), 's.customer_id', 's.sale_no as ref'])
            : DB::table('sales')->where('id', $sub->document_id)
                ->first(['sale_no as no', 'sold_at as at', DB::raw('(grand_total - tax_total) as net_total'), 'tax_total', 'grand_total', 'discount_total', 'customer_id', DB::raw('NULL as ref')]);
        $lines = $isReturn
            ? DB::table('sales_return_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->where('i.sales_return_id', $sub->document_id)
                ->get(['p.sku', 'p.name', 'p.hs_code', 'i.quantity', 'i.net_amount', 'i.tax_amount', 'i.line_total', DB::raw('0 as discount_amount')])
            : DB::table('sale_items as i')->join('products as p', 'p.id', '=', 'i.product_id')->where('i.sale_id', $sub->document_id)
                ->get(['p.sku', 'p.name', 'p.hs_code', 'i.quantity', 'i.net_amount', 'i.tax_amount', 'i.line_total', 'i.discount_amount']);
        $customer = $header->customer_id ? DB::table('customers')->where('id', $header->customer_id)->first(['name', 'phone', 'tax_no']) : null;
        $methods = $isReturn ? collect() : DB::table('sale_tenders')->where('sale_id', $sub->document_id)->pluck('method')->unique();
        $mode = $methods->count() > 1 ? 5 : match ($methods->first()) { 'card' => 2, 'credit' => 6, 'wallet', 'bank_transfer' => 2, default => 1 };
        $type = $isReturn ? 3 : 1;

        return [
            'InvoiceNumber' => '',
            'POSID' => (int) $s['pos_id'],
            'USIN' => $header->no,
            'DateTime' => substr((string) $header->at, 0, 19),
            'BuyerNTN' => $customer?->tax_no ?? '',
            'BuyerCNIC' => '',
            'BuyerName' => $customer?->name ?? '',
            'BuyerPhoneNumber' => $customer?->phone ?? '',
            'TotalBillAmount' => (float) $header->grand_total,
            'TotalQuantity' => (float) $lines->sum('quantity'),
            'TotalSaleValue' => (float) $header->net_total,
            'TotalTaxCharged' => (float) $header->tax_total,
            'Discount' => (float) $header->discount_total,
            'FurtherTax' => 0.0,
            'PaymentMode' => $mode,
            'RefUSIN' => $header->ref,
            'InvoiceType' => $type,
            'Items' => $lines->map(fn ($l): array => [
                'ItemCode' => $l->sku,
                'ItemName' => $l->name,
                'Quantity' => (float) $l->quantity,
                'PCTCode' => $l->hs_code ?? '',
                'TaxRate' => Decimal::isPositive((string) $l->net_amount) ? round((float) $l->tax_amount / (float) $l->net_amount * 100, 2) : 0.0,
                'SaleValue' => (float) $l->net_amount,
                'TotalAmount' => (float) $l->line_total,
                'TaxCharged' => (float) $l->tax_amount,
                'Discount' => (float) $l->discount_amount,
                'FurtherTax' => 0.0,
                'InvoiceType' => $type,
                'RefUSIN' => $header->ref,
            ])->values()->all(),
        ];
    }

    /** FBR Digital Invoicing payload (sales tax invoice to a buyer). */
    private function diPayload(FiscalSubmission $sub, array $s): array
    {
        $sale = DB::table('sales')->where('id', $sub->document_id)->first();
        $customer = $sale->customer_id ? DB::table('customers')->where('id', $sale->customer_id)->first() : null;
        $lines = DB::table('sale_items as i')->join('products as p', 'p.id', '=', 'i.product_id')
            ->leftJoin('units as u', 'u.id', '=', 'p.base_unit_id')->where('i.sale_id', $sale->id)
            ->get(['p.name', 'p.hs_code', 'u.name as uom', 'i.quantity', 'i.net_amount', 'i.tax_amount', 'i.line_total', 'i.tax_rate', 'i.discount_amount']);

        $payload = [
            'invoiceType' => 'Sale Invoice',
            'invoiceDate' => substr((string) $sale->sold_at, 0, 10),
            'sellerNTNCNIC' => (string) $s['seller_ntn'],
            'sellerBusinessName' => (string) $s['seller_name'],
            'sellerProvince' => (string) $s['seller_province'],
            'sellerAddress' => (string) $s['seller_address'],
            'buyerNTNCNIC' => (string) ($customer->tax_no ?? ''),
            'buyerBusinessName' => (string) ($customer->name ?? 'Walk-in'),
            'buyerProvince' => (string) ($customer->province ?? $s['seller_province']),
            'buyerAddress' => (string) ($customer->address ?? ''),
            'buyerRegistrationType' => ! empty($customer?->tax_no) ? 'Registered' : 'Unregistered',
            'invoiceRefNo' => '',
            'items' => $lines->map(fn ($l): array => [
                'hsCode' => $l->hs_code ?? '',
                'productDescription' => $l->name,
                'rate' => rtrim(rtrim((string) $l->tax_rate, '0'), '.').'%',
                'uoM' => $l->uom ?? 'Numbers, pieces, units',
                'quantity' => (float) $l->quantity,
                'totalValues' => (float) $l->line_total,
                'valueSalesExcludingST' => (float) $l->net_amount,
                'fixedNotifiedValueOrRetailPrice' => 0.0,
                'salesTaxApplicable' => (float) $l->tax_amount,
                'salesTaxWithheldAtSource' => 0.0,
                'extraTax' => '',
                'furtherTax' => 0.0,
                'sroScheduleNo' => '',
                'fedPayable' => 0.0,
                'discount' => (float) $l->discount_amount,
                'saleType' => 'Goods at standard rate (default)',
                'sroItemSerialNo' => '',
            ])->values()->all(),
        ];
        if (($s['di_environment'] ?? 'sandbox') !== 'live' && ! empty($s['di_scenario_id'])) {
            $payload['scenarioId'] = (string) $s['di_scenario_id'];
        }

        return $payload;
    }
}
