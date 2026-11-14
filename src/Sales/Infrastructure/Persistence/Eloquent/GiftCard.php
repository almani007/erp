<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class GiftCard extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'gift_cards';

    protected $fillable = ['company_id', 'branch_id', 'code', 'kind', 'initial_value', 'balance', 'customer_id', 'expires_on', 'status', 'paid_by', 'note', 'journal_entry_id', 'issued_by'];

    protected function casts(): array
    {
        return ['expires_on' => 'date:Y-m-d'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Customer, $this> */
    public function customer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }
}
