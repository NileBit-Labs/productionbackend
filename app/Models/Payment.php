<?php

namespace App\Models;

use App\Enums\PaymentMethod;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    protected $fillable = [
        'shop_id', 'sale_id', 'customer_id', 'supplier_id', 'purchase_id', 'amount', 'method',
        'reference', 'direction', 'recorded_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'method' => PaymentMethod::class,
        ];
    }
}
