<?php

declare(strict_types=1);

namespace Finance\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class Budget extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'budgets';

    protected $fillable = ['company_id', 'name', 'start_date', 'status', 'created_by'];

    protected function casts(): array
    {
        return ['start_date' => 'date:Y-m-d'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<BudgetLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BudgetLine::class);
    }
}
