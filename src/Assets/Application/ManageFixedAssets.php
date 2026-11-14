<?php

declare(strict_types=1);

namespace Assets\Application;

use Assets\Infrastructure\Persistence\Eloquent\AssetCategory;
use Assets\Infrastructure\Persistence\Eloquent\FixedAsset;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Illuminate\Support\Facades\DB;
use Purchasing\Infrastructure\Persistence\Eloquent\Supplier;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Asset register: acquisition and disposal, each with its journal.
 *
 * Acquisition   Dr Fixed assets   Cr Cash | Bank | Accounts payable | Opening balance equity
 *   (opening depreciation brought in: Dr Opening balance equity  Cr Accumulated depreciation)
 * Disposal      Dr Accumulated depreciation, Cash/Bank (proceeds)   Cr Fixed assets (cost)
 *               ± Gain / loss on disposal
 */
final class ManageFixedAssets
{
    public function __construct(
        private readonly PostJournalEntry $postJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    /** @param  array<string, mixed>  $data */
    public function register(string $companyId, array $data, string $actorId): FixedAsset
    {
        return DB::transaction(function () use ($companyId, $data, $actorId): FixedAsset {
            $category = AssetCategory::query()->findOrFail($data['category_id']);
            $cost = Decimal::round((string) $data['cost'], 2);
            $opening = Decimal::round((string) ($data['opening_depreciation'] ?? '0'), 2);
            $residual = isset($data['residual_value']) && $data['residual_value'] !== null
                ? Decimal::round((string) $data['residual_value'], 2)
                : Decimal::round(Decimal::div(Decimal::mul($cost, (string) $category->residual_percent, 6), '100', 4), 2);

            if (Decimal::cmp($opening, Decimal::sub($cost, $residual, 2)) > 0) {
                throw new \DomainException('Depreciation already charged cannot exceed cost less residual value.');
            }
            if ($data['funding'] === 'supplier' && empty($data['supplier_id'])) {
                throw new \DomainException('Choose the supplier the asset is owed to.');
            }

            $asset = FixedAsset::query()->create([
                'company_id' => $companyId,
                'branch_id' => $data['branch_id'] ?? null,
                'category_id' => $category->id,
                'asset_no' => $this->numbers->next($this->tenant->requireId(), 'fixed_asset', $companyId, 'FA'),
                'name' => $data['name'],
                'serial_no' => $data['serial_no'] ?? null,
                'location' => $data['location'] ?? null,
                'custodian_id' => $data['custodian_id'] ?? null,
                'supplier_id' => $data['supplier_id'] ?? null,
                'purchase_date' => $data['purchase_date'],
                'in_service_date' => $data['in_service_date'] ?? $data['purchase_date'],
                'cost' => $cost,
                'residual_value' => $residual,
                'method' => $data['method'] ?? $category->method,
                'useful_life_months' => $data['useful_life_months'] ?? $category->useful_life_months,
                'rate_percent' => $data['rate_percent'] ?? $category->rate_percent,
                'opening_depreciation' => $opening,
                'accumulated_depreciation' => $opening,
                'depreciated_to' => $data['depreciated_to'] ?? null,
                'funding' => $data['funding'],
                'status' => 'active',
                'notes' => $data['notes'] ?? null,
                'custom' => $data['custom'] ?? null,
                'created_by' => $actorId,
            ]);

            if ($asset->method === 'straight_line' && ! $asset->useful_life_months) {
                throw new \DomainException('Straight-line depreciation needs a useful life in months.');
            }
            if ($asset->method === 'reducing_balance' && ! Decimal::isPositive((string) $asset->rate_percent)) {
                throw new \DomainException('Reducing-balance depreciation needs an annual rate.');
            }

            $branch = $asset->branch_id;
            $credit = match ($data['funding']) {
                'cash' => JournalLineDraft::credit($this->accounts->id($companyId, 'cash'), $cost, $branch),
                'bank' => JournalLineDraft::credit($this->accounts->id($companyId, 'bank'), $cost, $branch),
                'supplier' => JournalLineDraft::credit($this->accounts->id($companyId, 'accounts_payable'), $cost, $branch, null, 'supplier', $data['supplier_id']),
                default => JournalLineDraft::credit($this->accounts->id($companyId, 'opening_balance_equity'), $cost, $branch),
            };
            $lines = [JournalLineDraft::debit($this->accounts->id($companyId, 'fixed_assets'), $cost, $branch, $asset->name), $credit];
            if (Decimal::isPositive($opening)) {
                $lines[] = JournalLineDraft::debit($this->accounts->id($companyId, 'opening_balance_equity'), $opening, $branch, 'Depreciation before entry');
                $lines[] = JournalLineDraft::credit($this->accounts->id($companyId, 'accumulated_depreciation'), $opening, $branch);
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId, postingDate: now()->toDateString(),
                sourceType: 'fixed_asset', sourceId: $asset->id, description: "Asset {$asset->asset_no} — {$asset->name}", lines: $lines,
            ));
            $asset->update(['acquisition_journal_id' => $journal->id]);

            if ($data['funding'] === 'supplier') {
                $supplier = Supplier::query()->lockForUpdate()->findOrFail($data['supplier_id']);
                $supplier->forceFill(['balance' => Decimal::add((string) $supplier->balance, $cost)])->save();
            }

            $this->audit->record('assets.asset.registered', 'fixed_asset', $asset->id, null, ['asset_no' => $asset->asset_no, 'cost' => $cost]);

            return $asset->load('category:id,name');
        });
    }

    public function dispose(string $assetId, string $date, string $proceeds, string $receivedBy, ?string $note): FixedAsset
    {
        return DB::transaction(function () use ($assetId, $date, $proceeds, $receivedBy, $note): FixedAsset {
            $asset = FixedAsset::query()->lockForUpdate()->findOrFail($assetId);
            if ($asset->status === 'disposed') {
                throw new \DomainException("{$asset->asset_no} is already disposed.");
            }

            $proceeds = Decimal::round($proceeds, 2);
            $cost = Decimal::round((string) $asset->cost, 2);
            $accumulated = Decimal::round((string) $asset->accumulated_depreciation, 2);
            $book = Decimal::sub($cost, $accumulated, 2);
            $gain = Decimal::sub($proceeds, $book, 2);
            $company = $asset->company_id;
            $branch = $asset->branch_id;

            $lines = [JournalLineDraft::credit($this->accounts->id($company, 'fixed_assets'), $cost, $branch, $asset->name)];
            if (Decimal::isPositive($accumulated)) {
                $lines[] = JournalLineDraft::debit($this->accounts->id($company, 'accumulated_depreciation'), $accumulated, $branch);
            }
            if (Decimal::isPositive($proceeds)) {
                $lines[] = JournalLineDraft::debit($this->accounts->id($company, $receivedBy === 'bank' ? 'bank' : 'cash'), $proceeds, $branch, 'Sale proceeds');
            }
            if (! Decimal::isZero($gain)) {
                $lines[] = Decimal::isPositive($gain)
                    ? JournalLineDraft::credit($this->accounts->id($company, 'asset_disposal'), $gain, $branch, 'Gain on disposal')
                    : JournalLineDraft::debit($this->accounts->id($company, 'asset_disposal'), Decimal::negate($gain, 2), $branch, 'Loss on disposal');
            }

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $company, postingDate: $date, sourceType: 'fixed_asset_disposal', sourceId: $asset->id,
                description: "Disposal of {$asset->asset_no} — {$asset->name}".($note ? ": {$note}" : ''), lines: $lines,
            ));

            $asset->update([
                'status' => 'disposed', 'disposal_date' => $date, 'disposal_proceeds' => $proceeds,
                'disposal_gain_loss' => $gain, 'disposal_journal_id' => $journal->id,
                'notes' => trim(($asset->notes ? $asset->notes."\n" : '').($note ?? '')) ?: null,
            ]);
            $this->audit->record('assets.asset.disposed', 'fixed_asset', $asset->id, null, ['asset_no' => $asset->asset_no, 'proceeds' => $proceeds, 'gain_loss' => $gain]);

            return $asset;
        });
    }
}
