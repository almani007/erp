<?php

declare(strict_types=1);

namespace Restaurant\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class DineOrderLine extends Model
{
    use HasUuid;

    protected $table = 'dine_order_lines';

    protected $fillable = ['dine_order_id', 'product_id', 'product_name', 'quantity', 'unit_price', 'modifiers', 'note', 'station', 'kitchen_status', 'kot_no', 'sent_at', 'ready_at', 'void_reason', 'created_by'];

    protected function casts(): array
    {
        return ['modifiers' => 'array', 'sent_at' => 'datetime', 'ready_at' => 'datetime'];
    }
}
