<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class PayrollLine extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'payroll_lines';

    protected $fillable = ['payroll_run_id', 'employee_id', 'payable_days', 'absent_days', 'overtime_hours', 'basic', 'allowances', 'overtime_amount', 'bonus', 'absence_deduction', 'gross', 'fixed_deductions', 'tax', 'other_deduction', 'advance_recovery', 'net', 'breakdown', 'note'];

    protected function casts(): array
    {
        return ['breakdown' => 'array'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Employee, $this> */
    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
