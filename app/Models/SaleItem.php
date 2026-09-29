<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SaleItem extends Model
{
    protected $fillable = [
        'sale_id', 'product_id', 'product_name', 'quantity', 'unit', 'unit_conversion',
        'unit_price', 'historical_cost', 'discount', 'line_total',
    ];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'unit_conversion' => 'float',
            'unit_price' => 'integer',
            'historical_cost' => 'integer',
            'discount' => 'integer',
            'line_total' => 'integer',
        ];
    }
}
