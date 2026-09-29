<?php

namespace App\Models;

use App\Enums\ExpenseType;
use Illuminate\Database\Eloquent\Model;

class ExpenseCategory extends Model
{
    /** name => the type an expense in it usually is. */
    public const DEFAULTS = [
        'Raw material transport' => ExpenseType::DirectProduction,
        'Production labour' => ExpenseType::DirectLabour,
        'Electricity' => ExpenseType::Operating,
        'Water' => ExpenseType::Operating,
        'Fuel' => ExpenseType::Operating,
        'Delivery' => ExpenseType::Operating,
        'Rent' => ExpenseType::Operating,
        'Salaries' => ExpenseType::Operating,
        'Repairs' => ExpenseType::Operating,
        'Marketing' => ExpenseType::Operating,
        'Transport' => ExpenseType::Operating,
        'Utilities' => ExpenseType::Operating,
        'Airtime & data' => ExpenseType::Operating,
        'Licences & taxes' => ExpenseType::Operating,
        'Supplies' => ExpenseType::Operating,
        'Other' => ExpenseType::Operating,
    ];

    protected $fillable = ['shop_id', 'name', 'default_type', 'is_active'];

    protected function casts(): array
    {
        return [
            'default_type' => ExpenseType::class,
            'is_active' => 'boolean',
        ];
    }
}
