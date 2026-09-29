<?php

namespace App\Models;

use App\Enums\ExpenseType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Expense extends Model
{
    public const CATEGORIES = [
        'Rent', 'Salaries', 'Transport', 'Utilities', 'Airtime & data',
        'Repairs', 'Licences & taxes', 'Supplies', 'Other',
    ];

    protected $attributes = ['type' => 'operating'];

    protected $fillable = ['shop_id', 'category', 'type', 'production_batch_id', 'amount', 'description', 'recorded_by', 'expense_date'];

    protected function casts(): array
    {
        return [
            'amount' => 'integer',
            'expense_date' => 'date:Y-m-d',
            'type' => ExpenseType::class,
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(ProductionBatch::class, 'production_batch_id');
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }
}
