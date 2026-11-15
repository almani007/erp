<?php

declare(strict_types=1);

namespace Sales\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Shared\Infrastructure\Concern\HasUuid;

final class DeliveryNoteLine extends Model
{
    use HasUuid;

    public $timestamps = false;

    protected $table = 'delivery_note_lines';

    protected $fillable = ['delivery_note_id', 'sale_item_id', 'product_id', 'description', 'quantity'];
}
