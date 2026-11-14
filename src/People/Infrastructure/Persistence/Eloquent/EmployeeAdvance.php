<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class EmployeeAdvance extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'employee_advances';

    protected $fillable = ['company_id', 'employee_id', 'advance_no', 'date', 'kind', 'amount', 'installment', 'recovered', 'paid_from', 'status', 'note', 'journal_entry_id', 'created_by'];

    protected function casts(): array
    {
        return ['date' => 'date:Y-m-d'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Employee, $this> */
    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }
}
