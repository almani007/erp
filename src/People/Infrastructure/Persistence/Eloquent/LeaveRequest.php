<?php

declare(strict_types=1);

namespace People\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class LeaveRequest extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'leave_requests';

    protected $fillable = ['employee_id', 'leave_type_id', 'from_date', 'to_date', 'half_day', 'days', 'reason', 'status', 'requested_by', 'decided_by', 'decided_at', 'decision_note'];

    protected function casts(): array
    {
        return ['half_day' => 'boolean', 'from_date' => 'date:Y-m-d', 'to_date' => 'date:Y-m-d', 'decided_at' => 'datetime'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Employee, $this> */
    public function employee(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<LeaveType, $this> */
    public function type(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(LeaveType::class, 'leave_type_id');
    }
}
