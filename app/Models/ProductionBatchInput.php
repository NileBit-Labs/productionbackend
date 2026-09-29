<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionBatchInput extends Model
{
    protected $fillable = ['production_batch_id', 'product_id', 'planned_quantity', 'actual_quantity', 'unit_cost', 'line_cost'];

    protected function casts(): array
    {
        return [
            'planned_quantity' => 'float',
            'actual_quantity' => 'float',
            'unit_cost' => 'integer',
            'line_cost' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }
}
