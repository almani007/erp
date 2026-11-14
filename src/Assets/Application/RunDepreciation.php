<?php

declare(strict_types=1);

namespace Assets\Application;

use Assets\Infrastructure\Persistence\Eloquent\DepreciationRun;
use Assets\Infrastructure\Persistence\Eloquent\FixedAsset;
use Carbon\CarbonImmutable;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Application\PostingAccounts;
use Finance\Application\PostJournalEntry;
use Finance\Application\ReverseJournalEntry;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\AuditTrail;
use Shared\Domain\Contract\DocumentNumbers;
use Shared\Domain\Contract\TenantContext;
use Shared\Domain\Support\Decimal;

/**
 * Month-end depreciation for every active asset of a company, as one
 * journal (Dr Depreciation expense, by branch   Cr Accumulated depreciation).
 * Only the latest run can be reversed.
 */
final class RunDepreciation
{
    public function __construct(
        private readonly DepreciationCalculator $calculator,
        private readonly PostJournalEntry $postJournal,
        private readonly ReverseJournalEntry $reverseJournal,
        private readonly PostingAccounts $accounts,
        private readonly DocumentNumbers $numbers,
        private readonly TenantContext $tenant,
        private readonly AuditTrail $audit,
    ) {}

    /** @return list<array{asset: FixedAsset, amount: string, months: int}> */
    public function preview(string $companyId, string $period): array
    {
        $end = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth();
        $out = [];
        foreach (FixedAsset::query()->where('company_id', $companyId)->where('status', 'active')->orderBy('asset_no')->get() as $asset) {
            $charge = $this->calculator->charge($asset, $end);
            if (Decimal::isPositive($charge['amount'])) {
                $out[] = ['asset' => $asset, 'amount' => $charge['amount'], 'months' => $charge['months']];
            }
        }

        return $out;
    }

    public function run(string $companyId, string $period, string $actorId): DepreciationRun
    {
        return DB::transaction(function () use ($companyId, $period, $actorId): DepreciationRun {
            if (DepreciationRun::query()->where('company_id', $companyId)->where('status', 'posted')->where('period', '>=', $period)->exists()) {
                throw new \DomainException("Depreciation has already been run for {$period} or a later month.");
            }

            $end = CarbonImmutable::createFromFormat('!Y-m', $period)->endOfMonth();
            $charges = $this->preview($companyId, $period);
            if ($charges === []) {
                throw new \DomainException("No asset has depreciation to charge for {$period}.");
            }

            $run = DepreciationRun::query()->create([
                'company_id' => $companyId,
                'run_no' => $this->numbers->next($this->tenant->requireId(), 'depreciation_run', $companyId, 'DEP'),
                'period' => $period, 'total' => '0', 'asset_count' => count($charges), 'status' => 'posted', 'created_by' => $actorId,
            ]);

            $total = '0';
            $byBranch = [];
            foreach ($charges as ['asset' => $asset, 'amount' => $amount]) {
                $asset = FixedAsset::query()->lockForUpdate()->findOrFail($asset->id);
                $after = Decimal::add((string) $asset->accumulated_depreciation, $amount, 2);
                $run->lines()->create([
                    'fixed_asset_id' => $asset->id, 'amount' => $amount, 'accumulated_after' => $after,
                    'previous_depreciated_to' => $asset->depreciated_to?->toDateString(),
                ]);
                $fully = Decimal::cmp($after, Decimal::sub((string) $asset->cost, (string) $asset->residual_value, 2)) >= 0;
                $asset->update(['accumulated_depreciation' => $after, 'depreciated_to' => $end->toDateString(), 'status' => $fully ? 'fully_depreciated' : 'active']);

                $total = Decimal::add($total, $amount, 2);
                $key = $asset->branch_id ?? '';
                $byBranch[$key] = Decimal::add($byBranch[$key] ?? '0', $amount, 2);
            }

            $lines = [];
            foreach ($byBranch as $branch => $amount) {
                $lines[] = JournalLineDraft::debit($this->accounts->id($companyId, 'depreciation_expense'), $amount, $branch ?: null);
            }
            $lines[] = JournalLineDraft::credit($this->accounts->id($companyId, 'accumulated_depreciation'), $total);

            $journal = $this->postJournal->execute(new JournalDraft(
                companyId: $companyId, postingDate: $end->toDateString(), sourceType: 'depreciation_run', sourceId: $run->id,
                description: "Depreciation {$period} ({$run->run_no})", lines: $lines,
            ));

            $run->update(['total' => $total, 'journal_entry_id' => $journal->id]);
            $this->audit->record('assets.depreciation.run', 'depreciation_run', $run->id, null, ['period' => $period, 'total' => $total, 'assets' => count($charges)]);

            return $run->load('lines');
        });
    }

    public function reverse(string $runId, string $reason): DepreciationRun
    {
        return DB::transaction(function () use ($runId, $reason): DepreciationRun {
            $run = DepreciationRun::query()->lockForUpdate()->findOrFail($runId);
            if ($run->status !== 'posted') {
                throw new \DomainException("{$run->run_no} is already reversed.");
            }
            if (DepreciationRun::query()->where('company_id', $run->company_id)->where('status', 'posted')->where('period', '>', $run->period)->exists()) {
                throw new \DomainException('Reverse the later depreciation runs first.');
            }

            foreach ($run->lines as $line) {
                $asset = FixedAsset::query()->lockForUpdate()->findOrFail($line->fixed_asset_id);
                if ($asset->status === 'disposed') {
                    throw new \DomainException("{$asset->asset_no} has been disposed since; this run can no longer be reversed.");
                }
                $asset->update([
                    'accumulated_depreciation' => Decimal::sub((string) $asset->accumulated_depreciation, (string) $line->amount, 2),
                    'depreciated_to' => $line->previous_depreciated_to?->toDateString(),
                    'status' => 'active',
                ]);
            }

            $this->reverseJournal->execute($run->journal_entry_id, $reason, now()->toDateString());
            $run->update(['status' => 'reversed']);
            $this->audit->record('assets.depreciation.reversed', 'depreciation_run', $run->id, null, ['period' => $run->period], $reason);

            return $run;
        });
    }
}
