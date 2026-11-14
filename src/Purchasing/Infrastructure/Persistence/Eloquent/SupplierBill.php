<?php

declare(strict_types=1);

namespace Purchasing\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

final class SupplierBill extends Model
{
    use BelongsToTenant;
    use HasUuid;

    protected $table = 'supplier_bills';

    protected $fillable = ['company_id', 'branch_id', 'supplier_id', 'bill_no', 'supplier_invoice_no', 'bill_date', 'due_date', 'received_value', 'variance', 'expense_total', 'expense_tax', 'grand_total', 'balance_due', 'status', 'notes', 'journal_entry_id', 'created_by', 'currency', 'fx_rate', 'foreign_total', 'foreign_balance_due'];

    protected function casts(): array
    {
        return ['bill_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d'];
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<SupplierBillLine, $this> */
    public function lines(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(SupplierBillLine::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\HasMany<GoodsReceipt, $this> */
    public function receipts(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(GoodsReceipt::class);
    }

    /** @return \Illuminate\Database\Eloquent\Relations\BelongsTo<Supplier, $this> */
    public function supplier(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
