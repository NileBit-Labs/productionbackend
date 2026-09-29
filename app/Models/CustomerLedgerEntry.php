<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CustomerLedgerEntry extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = [
        'shop_id', 'customer_id', 'type', 'amount', 'reference_type', 'reference_id',
        'note', 'recorded_by',
    ];

    protected function casts(): array
    {
        return ['amount' => 'integer'];
    }
}
