<?php

declare(strict_types=1);

namespace Finance\Application;

use Carbon\CarbonImmutable;
use Finance\Application\Dto\JournalDraft;
use Finance\Application\Dto\JournalLineDraft;
use Finance\Infrastructure\Persistence\Eloquent\RecurringJournal;
use Illuminate\Support\Facades\DB;
use Shared\Domain\Contract\TenantContext;

/**
 * Posts recurring journals that have fallen due (rent, accruals, loan
 * instalments). Runs daily from the scheduler for every tenant, and on
 * demand from the screen. A missed date is caught up one posting at a time.
 */
final class RunRecurringJournals
{
    public function __construct(private readonly PostJournalEntry $postJournal, private readonly TenantContext $tenant) {}

    /** @return array{posted: int, failed: int} */
    /** `$tenantId` null = every tenant (the scheduler); a request passes its own. */
    public function runDue(?string $tenantId, ?string $today = null, ?string $onlyId = null): array
    {
        $today ??= now()->toDateString();
        $posted = $failed = 0;

        $due = DB::table('recurring_journals')->where('status', 'active')->where('next_run_date', '<=', $today)
            ->when($tenantId !== null, fn ($q) => $q->where('tenant_id', $tenantId))
            ->when($onlyId !== null, fn ($q) => $q->where('id', $onlyId))
            ->orderBy('next_run_date')->get(['id', 'tenant_id']);

        foreach ($due as $row) {
            // Console runs have no signed-in user: act inside the template's tenant.
            $this->tenant->set($row->tenant_id);

            // Catch up missed dates, at most a year of them per run.
            for ($i = 0; $i < 12; $i++) {
                $result = $this->postOne($row->id, $today);
                if ($result === null) {
                    break;
                }
                $result ? $posted++ : $failed++;
                if (! $result) {
                    break;
                }
            }
        }

        return ['posted' => $posted, 'failed' => $failed];
    }

    /** @return bool|null true posted, false failed, null nothing due */
    private function postOne(string $id, string $today): ?bool
    {
        try {
            return DB::transaction(function () use ($id, $today): ?bool {
                $template = RecurringJournal::query()->lockForUpdate()->find($id);
                if ($template === null || $template->status !== 'active' || $template->next_run_date->toDateString() > $today) {
                    return null;
                }
                $date = $template->next_run_date->toDateString();
                if ($template->end_date !== null && $date > $template->end_date->toDateString()) {
                    $template->update(['status' => 'finished']);

                    return null;
                }

                $lines = array_map(static fn (array $l): JournalLineDraft => ((float) ($l['debit'] ?? 0)) > 0
                    ? JournalLineDraft::debit($l['account_id'], (string) $l['debit'], $l['branch_id'] ?? null, $l['description'] ?? null)
                    : JournalLineDraft::credit($l['account_id'], (string) $l['credit'], $l['branch_id'] ?? null, $l['description'] ?? null), $template->lines);

                $journal = $this->postJournal->execute(new JournalDraft(
                    companyId: $template->company_id, postingDate: $date, sourceType: 'recurring_journal', sourceId: $template->id,
                    description: $template->description ?: $template->name, lines: $lines,
                ));

                $next = self::advance(CarbonImmutable::parse($date), $template->frequency);
                $finished = $template->end_date !== null && $next->toDateString() > $template->end_date->toDateString();
                $template->update([
                    'next_run_date' => $next->toDateString(), 'runs_count' => $template->runs_count + 1, 'last_run_at' => now(),
                    'last_journal_id' => $journal->id, 'last_error' => null, 'status' => $finished ? 'finished' : 'active',
                ]);

                return true;
            });
        } catch (\Throwable $e) {
            // A closed period or a deactivated account: keep the template, show why.
            RecurringJournal::query()->whereKey($id)->update(['last_error' => mb_substr($e->getMessage(), 0, 500)]);

            return false;
        }
    }

    public static function advance(CarbonImmutable $date, string $frequency): CarbonImmutable
    {
        return match ($frequency) {
            'quarterly' => $date->addMonthsNoOverflow(3),
            'yearly' => $date->addYearNoOverflow(),
            'weekly' => $date->addWeek(),
            default => $date->addMonthNoOverflow(),
        };
    }
}
