<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class PosShift extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'pos_shifts';

    protected $fillable = [
        'company_id', 'branch_id', 'terminal_id', 'cashier_id', 'shift_no', 'status', 'opening_cash',
        'expected_cash', 'counted_cash', 'variance', 'notes', 'opened_at', 'closed_at',
    ];

    protected function casts(): array
    {
        return ['opened_at' => 'datetime', 'closed_at' => 'datetime'];
    }

    public function isOpen(): bool
    {
        return $this->status === 'open';
    }

    /** @return BelongsTo<PosTerminal, $this> */
    public function terminal(): BelongsTo
    {
        return $this->belongsTo(PosTerminal::class, 'terminal_id');
    }
}
