<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Shared\Infrastructure\Concern\BelongsToTenant;
use Shared\Infrastructure\Concern\HasUuid;

/** A quotation or a sales order (doc_type). */
final class SalesOrder extends Model
{
    use BelongsToTenant;
    use HasUuid;

    public const string QUOTATION = 'quotation';

    public const string ORDER = 'order';

    protected $table = 'sales_orders';

    protected $fillable = [
        'company_id', 'branch_id', 'warehouse_id', 'customer_id', 'doc_type', 'doc_no', 'status', 'doc_date', 'valid_until',
        'delivery_date', 'payment_days', 'price_tier', 'reference', 'notes', 'terms', 'subtotal', 'discount_total', 'tax_total',
        'grand_total', 'source_id', 'salesperson_id', 'created_by',
    ];

    protected function casts(): array
    {
        return ['doc_date' => 'date:Y-m-d', 'valid_until' => 'date:Y-m-d', 'delivery_date' => 'date:Y-m-d'];
    }

    /** @return HasMany<SalesOrderLine, $this> */
    public function lines(): HasMany
    {
        return $this->hasMany(SalesOrderLine::class)->orderBy('line_no');
    }

    /** @return BelongsTo<Customer, $this> */
    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class);
    }

    /** @return HasMany<Sale, $this> */
    public function invoices(): HasMany
    {
        return $this->hasMany(Sale::class, 'sales_order_id');
    }
}
