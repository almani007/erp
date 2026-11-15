<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Employee extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'employees';

    protected $fillable = ['company_id', 'branch_id', 'code', 'name', 'father_name', 'cnic', 'phone', 'email', 'address', 'department', 'designation', 'join_date', 'leave_date', 'pay_type', 'basic_salary', 'allowances', 'deductions', 'monthly_tax', 'tax_method', 'annual_leave_days', 'bank_name', 'bank_account', 'user_id', 'status', 'custom'];

    protected function casts(): array
    {
        return ['allowances' => 'array', 'deductions' => 'array', 'custom' => 'array', 'join_date' => 'date:Y-m-d', 'leave_date' => 'date:Y-m-d'];
    }
}
