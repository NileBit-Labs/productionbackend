<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RefundItem extends Model
{
    protected $fillable = ['refund_id', 'sale_item_id', 'quantity', 'amount', 'restock'];

    protected function casts(): array
    {
        return [
            'quantity' => 'float',
            'amount' => 'integer',
            'restock' => 'boolean',
        ];
    }
}
