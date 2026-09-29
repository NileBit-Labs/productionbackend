<?php

namespace App\Support;

use App\Models\ExpenseCategory;
use App\Models\MeasurementUnit;
use App\Models\Shop;

/**
 * Gives a shop its starting measurement units and expense categories the first time they are
 * asked for, so every business starts usable and can then rename, add or retire its own.
 */
final class ShopDefaults
{
    public static function units(Shop $shop): void
    {
        if (MeasurementUnit::where('shop_id', $shop->id)->exists()) {
            return;
        }

        foreach (MeasurementUnit::DEFAULTS as $unit) {
            MeasurementUnit::firstOrCreate(['shop_id' => $shop->id, 'symbol' => $unit['symbol']], $unit);
        }
    }

    public static function expenseCategories(Shop $shop): void
    {
        if (ExpenseCategory::where('shop_id', $shop->id)->exists()) {
            return;
        }

        foreach (ExpenseCategory::DEFAULTS as $name => $type) {
            ExpenseCategory::firstOrCreate(['shop_id' => $shop->id, 'name' => $name], ['default_type' => $type]);
        }
    }
}
