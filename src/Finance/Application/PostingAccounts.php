<?php

declare(strict_types=1);

namespace Finance\Application;

use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\TenantContext;

/**
 * Resolves the ledger account an automatic posting should use, per company.
 * The mapping is data (posting_accounts), so each tenant's accountant can
 * point "cash" or "sales_revenue" at their own chart.
 */
final class PostingAccounts
{
    public const array KEYS = [
        'cash', 'bank', 'accounts_receivable', 'inventory', 'accounts_payable', 'sales_tax_payable',
        'sales_revenue', 'cost_of_goods_sold', 'inventory_adjustment', 'opening_balance_equity', 'cash_over_short',
        'purchase_tax_receivable', 'loyalty_redemption',
    ];

    /** @var array<string, array<string, string>> */
    private array $cache = [];

    public function __construct(private readonly TenantContext $tenant) {}

    public function id(string $companyId, string $key): string
    {
        $this->cache[$companyId] ??= DB::table('posting_accounts')
            ->where('tenant_id', $this->tenant->requireId())
            ->where('company_id', $companyId)
            ->pluck('account_id', 'key')
            ->all();

        return $this->cache[$companyId][$key]
            ?? throw new \DomainException("No ledger account is mapped for '{$key}'. Set it under Finance → Posting accounts.");
    }
}
