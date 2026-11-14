<?php

declare(strict_types=1);

namespace Catalog\Infrastructure\Persistence\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

final class PromotionItem extends Model
{
    protected $table = 'promotion_items';

    protected $fillable = ['promotion_rule_id', 'product_id', 'variant_id', 'category_id', 'brand_id'];

    public function promotionRule(): BelongsTo
    {
        return $this->belongsTo(PromotionRule::class, 'promotion_rule_id');
    }
}
