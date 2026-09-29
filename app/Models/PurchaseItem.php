<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PurchaseItem extends Model
{
    protected $fillable = [
        'purchase_id', 'product_id', 'quantity', 'unit_name', 'conversion', 'unit_cost',
        'line_total', 'base_quantity', 'base_unit_cost', 'cost_before', 'cost_after',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'conversion' => 'float',
            'base_quantity' => 'float',
            'unit_cost' => 'integer',
            'line_total' => 'integer',
            'base_unit_cost' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
