<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class BudgetLine extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'budget_lines';

    protected $fillable = ['budget_id', 'account_id', 'branch_id', 'amounts'];

    protected function casts(): array
    {
        return ['amounts' => 'array'];
    }
}
