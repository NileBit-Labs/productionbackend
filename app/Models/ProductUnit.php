<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductUnit extends Model
{
    protected $fillable = ['product_id', 'unit_name', 'conversion_to_base_unit', 'selling_price'];

    protected function casts(): array
    {
        return [
            'conversion_to_base_unit' => 'float',
            'selling_price' => 'integer',
        ];
    }
}
