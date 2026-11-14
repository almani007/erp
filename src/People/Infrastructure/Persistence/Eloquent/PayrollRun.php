<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class PayrollRun extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'payroll_runs';

    protected $fillable = ['company_id', 'branch_id', 'run_no', 'period', 'period_start', 'period_end', 'status', 'gross_total', 'deduction_total', 'net_total', 'journal_entry_id', 'payment_journal_entry_id', 'created_by', 'approved_by', 'approved_at', 'paid_at'];

    protected function casts(): array
    {
        return ['period_start' => 'date:Y-m-d', 'period_end' => 'date:Y-m-d', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<PayrollLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(PayrollLine::class);
    }
}
