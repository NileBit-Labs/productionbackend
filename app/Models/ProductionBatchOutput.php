<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductionBatchOutput extends Model
{
    protected $fillable = [
        'production_batch_id', 'product_id', 'quantity', 'output_equivalent', 'allocated_cost', 'unit_cost',
        'expiry_date', 'cost_before', 'cost_after',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'output_equivalent' => 'float',
            'allocated_cost' => 'integer',
            'unit_cost' => 'integer',
            'cost_before' => 'integer',
            'cost_after' => 'integer',
            'expiry_date' => 'date:Y-m-d',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function lot(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(ProductionLot::class, 'production_batch_output_id');
    }
}
