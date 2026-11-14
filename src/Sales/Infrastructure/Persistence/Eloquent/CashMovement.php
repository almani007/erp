<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class CashMovement extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public $timestamps = false;

    protected $table = 'pos_cash_movements';

    protected $fillable = ['shift_id', 'type', 'amount', 'reason', 'created_by', 'created_at'];
}
