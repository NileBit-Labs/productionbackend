<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Refund extends Model
{
    protected $fillable = [
        'shop_id', 'sale_id', 'total_refund', 'cash_refund', 'balance_credit', 'method',
        'reason', 'approved_by', 'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'total_refund' => 'integer',
            'cash_refund' => 'integer',
            'balance_credit' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(RefundItem::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }
}
