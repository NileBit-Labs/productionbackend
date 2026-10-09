<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class PurchaseReturn extends Model
{
    protected $fillable = ['shop_id', 'purchase_id', 'total', 'balance_credit', 'cash_refund', 'reason', 'idempotency_key', 'recorded_by'];
}
