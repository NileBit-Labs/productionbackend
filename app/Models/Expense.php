<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = [
        'Rent', 'Salaries', 'Transport', 'Utilities', 'Airtime & data',
        'Repairs', 'Licences & taxes', 'Supplies', 'Other',
    ];

    protected $fillable = ['shop_id', 'category', 'amount', 'description', 'recorded_by', 'expense_date'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expense_date' => 'date:Y-m-d',
        ];
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
